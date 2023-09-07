<?php

namespace App\Helper;

use App\Helper\MainModel;
use App\Models\PlatformObjectData;
use DB;
use App\Models\EsRegionalTimeZone;
use App\Models\PlatformStates;

class MappingHelper
{
    public $objectCollection = [];
    public function getObjectList($allRequiredObj)
    {
        for ($i = 0; $i < count($allRequiredObj); $i++) {
            $this->objectCollection[$allRequiredObj[$i]->name] = [
                'id' => $allRequiredObj[$i]->id,
                'linked_with' => $allRequiredObj[$i]->linked_with, 'linked_with_id' => $allRequiredObj[$i]->linked_with_id,
                'store_with' => $allRequiredObj[$i]->store_with, 'store_with_id' => $allRequiredObj[$i]->store_with_id,
                'display_name' => $allRequiredObj[$i]->display_name,
                'linked_table' => $allRequiredObj[$i]->linked_table
            ];
        }
    }
    //return object id for store & get mapping data purpose
    public function getPlatformObjectId($objectName)
    {
        if (array_key_exists($objectName, $this->objectCollection)) {
            $objId = $this->objectCollection[$objectName]['id'];
            $linked_table = $this->objectCollection[$objectName]['linked_table'];
            //get linked with object data
            $linked_with = $this->objectCollection[$objectName]['linked_with'];
            if ($linked_with) {
                $linked_with_id = $this->objectCollection[$objectName]['linked_with_id'];
            } else {
                $linked_with = "";
                $linked_with_id = "";
            }

            //get store with data
            $store_with = $this->objectCollection[$objectName]['store_with'];
            if ($store_with) {
                $store_with_id = $this->objectCollection[$objectName]['store_with_id'];
            } else {
                $store_with = "";
                $store_with_id = "";
            }


            $arr = array(
                'id' => $objId, 'linked_with' => $linked_with, 'linked_with_id' => $linked_with_id,
                'store_with' => $store_with, 'store_with_id' => $store_with_id, 'linked_table' => $linked_table
            );
            return $arr = (object) $arr;
        }
    }
    //get object id from db
    public function getPlatformObjectIdFromDb($objectName)
    {
        return DB::table('platform_objects')->select('id', 'display_name')->where('name', $objectName)->first();
    }

    //return platform object data for mapping fields
    public function getPlatformObjectData($platformId, $userIntegId, $userId, $platform_object_id, $platformName = null, $pobjName = null, $parentId = null)
    {
        $platformName = strtolower($platformName);

        $data = PlatformObjectData::select('platform_id', 'id as optionId', 'name as optionValue')->where('platform_id', $platformId)->where('platform_object_id', $platform_object_id);

        $userIds = [$userId, 0];
        $integrationIds = [$userIntegId, 0];

        if ($parentId) {
            $data->where('parent_id', $parentId);
        }

        if (($platformName == "brightpearl") && ($pobjName == "scancelled_order_status" || $pobjName == "sorder_approval_status" || $pobjName == "sorder_status" || $pobjName == "get_sorder_status" || $pobjName == "sorder_status_filter" || $pobjName == "default_final_sorder_status" || $pobjName == "sorder_error_status")) {
            $data->where('api_code', 'SO');
        } elseif (($platformName == "brightpearl") && ($pobjName == "get_order_status" || $pobjName == "porder_status_filter" || $pobjName == "default_porder_status" || $pobjName == "default_purchase_invoice_status")) {
            $data->where('api_code', 'PO');
        } elseif (($platformName == "brightpearl") && ($pobjName == "default_scredit_status" || $pobjName == "default_final_sales_credit_status" || $pobjName == "scorder_status" || $pobjName == "sales_credit_status_filter")) {
            $data->where('api_code', 'SC');
        } elseif (($platformName == "bigcommerce") && ($pobjName == "refund_payment_method")) {
            //return only user specific dynamic record
            $userIds = [$userId];
            $integrationIds = [$userIntegId];
        } elseif (($platformName == "quickbooks") && ($pobjName == "default_account_number")) {

            $data->where('api_code', 'Accounts Payable');
        } elseif (($platformName == "quickbooks") && ($pobjName == "default_income_account_ref")) {

            $data->where('api_code', 'Income')->where('other_code', 'SalesOfProductIncome');
        } elseif (($platformName == "quickbooks") && ($pobjName == "default_asset_account_ref")) {

            $data->where('api_code', 'Other Current Asset')->where('other_code', 'Inventory');
        } elseif (($platformName == "quickbooks") && ($pobjName == "default_expense_account_ref")) {

            $data->where('api_code', 'Cost of Goods Sold')->where('other_code', 'SuppliesMaterialsCogs');
        } elseif (($platformName == "quickbooks") && ($pobjName == "deposite_to_account_ref")) {

            $data->whereIn('api_code', ['Bank', 'Other Current Asset']);
        }

        $response = $data->whereIn('user_id', $userIds)->whereIn('user_integration_id', $integrationIds)->where('status', 1)->get();
        return $response;
    }

    public function getTimezoneData()
    {
        $data = EsRegionalTimeZone::select('id as optionId', DB::raw("CONCAT(`time_zone`,' ','(', `gmt_offset`,')') AS optionValue"))->get();
        return $data;
    }

    public function getStatesData()
    {
        $data = PlatformStates::select('id as optionId', DB::raw("CONCAT(`name`,' ','(', `iso2`,')') AS optionValue"))->where('country_code', 'US')->get();
        return $data;
    }
    //return platform fields data
    public function getPlatformFieldData($platformId, $userIntegId, $userId, $platform_object_id, $platformName = null, $pobjName = null, $fieldType = ['custom', 'default'])
    {
        //'name as optionValue',
        $data = DB::table('platform_fields')
            ->select('platform_id', 'id as optionId', 'description as optionValue')
            ->where('platform_id', $platformId)
            ->whereIn('user_id', [$userId, 0])
            ->whereIn('user_integration_id', [$userIntegId, 0])
            ->whereIn('field_type', $fieldType)
            ->where('platform_object_id', $platform_object_id)
            ->where('status', 1)
            ->get();
        return $data;
    }
    public function getSelectedIndex($platform_object_id, $type, $optionId, $userIntegId, $pfwfrID, $data_map_type, $mapping_type)
    {
        //here i have used mapping_type where class with default type for default text,number,email or other
        return DB::table('platform_data_mapping')
            ->where('platform_object_id', $platform_object_id)->where($type, $optionId)->where('data_map_type', $data_map_type)
            ->where('user_integration_id', $userIntegId)->where('platform_workflow_rule_id', $pfwfrID)->where('status', 1)
            ->where('mapping_type', $mapping_type)
            ->first();
    }
    //get Insert id for selected mapping
    public function getInsertId($platform_object_id, $type, $userIntegId, $pfwfrID, $pobjName = null)
    {
        $objectType = strtok($pobjName, '_');
        //if($pobjName=="default_order_payment" || $pobjName=="default_sorder_taxcode" || $pobjName=="default_sorder_shipping_method")
        if ($objectType == "default") {
            return DB::table('platform_data_mapping')->select('id')->where('platform_object_id', $platform_object_id)->where('data_map_type', $type)
                ->where('platform_workflow_rule_id', $pfwfrID)
                ->where('mapping_type', 'default')
                ->where('user_integration_id', $userIntegId)->first();
        } else {
            return DB::table('platform_data_mapping')->select('id')->where('platform_object_id', $platform_object_id)->where('data_map_type', $type)
                ->where('platform_workflow_rule_id', $pfwfrID)->where('user_integration_id', $userIntegId)->first();
        }
    }
    //make dynamic label for mapping default or regular
    public function dynamicMappingLabel($sourceFeature, $destFeature, $pobjName = null)
    {
        //get Display Name
        if ($pobjName) {
            if (array_key_exists($pobjName, $this->objectCollection)) {

                $display_name = $this->objectCollection[$pobjName]['display_name'];
                if ($display_name) {
                    return $display_name;
                } else {
                    $pobjName = str_replace("default_", " ", $pobjName);
                    $pobjName = str_replace("_ms", " ", $pobjName);
                    $pobjName = str_replace("_", " ", $pobjName);
                    $pobjName = str_replace("porder", " Purchase Order ", $pobjName);
                    $pobjName = str_replace("sorder", " Sales Order ", $pobjName);
                    $pobjName = str_replace("scancelled", " Sales Cancelled ", $pobjName);
                    $pobjName = ucwords($pobjName);

                    if ($sourceFeature == "ON" && $destFeature == "ON") {
                        return $labelText = "" . $pobjName;
                    } else {
                        return $labelText = "Default " . $pobjName;
                    }
                }
            }
        }
    }
    //custom validation text *
    function getValidationText($validationStatus)
    {
        $applyMsg = "";
        if ($validationStatus == 1) {
            $applyMsg = "*";
            return "<span style='color:red;padding-left:5px;font-size:18px;'>" . $applyMsg . "</span>";
        } else {
            return "<span style='color:red;padding-left:5px;font-size:18px;'>&nbsp;</span>";
        }
        // modified by @GK (03-09-2022)
        // return "<span style='color:red !important; padding-left:5px; font-size:18px;'>".($validationStatus) ? '*' : '&nbsp;'."</span>";

    }

    function getDataRetentionView($integ_dr_status, $integ_dr_period, $ui_dr_status, $ui_dr_period)
    {
        $status = ($integ_dr_status == 1) ? 'checked' : '';
        $timePeriod = ($integ_dr_period) ? $integ_dr_period : 0;
        $sec_display_style = ($integ_dr_status == 1) ? 'display:block' : 'display:none';

        //manage Data retention by user integration Level
        if ($ui_dr_status == 0) {
            $status = '';
            $timePeriod = $integ_dr_period;
            $sec_display_style = 'display:none';
        } else if ($ui_dr_status == 1) {
            $status = 'checked';
            $timePeriod = $ui_dr_period;
            $sec_display_style = 'display:block';
        }


        $Content = "";
        $Content .= '  <div class="row">
        <div class="col-sm-6" style="display:flex">
            <label style="display: flex !important;text-align:left !important; font-weight:bold;margin-top: 2px;">Data Retention Policy</label> &nbsp;&nbsp;
            <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="data_retention_switch" onclick="handleDataRetention()" ' . $status . '><label class="custom-control-label" for="data_retention_switch"></label></div>
        </div>

        <div class="col-sm-6 data_retention_period_section" style="display: flex;">
        <div class="col-md-6 data_retention_period_section" style="' . $sec_display_style . '">
        <label style="display: flex !important;text-align:left !important; font-weight:bold;margin-top: 10px;">Data Retention After (In Days) &nbsp;&nbsp; <i class="fa fa-question-circle" aria-hidden="true" style="font-size:18px" data-toggle="tooltip" data-placement="top" title="Set a data retention period in days to delete unuseful data automatically" style="cursor:pointer"></i></label>
        </div>
        <div class="col-md-6 data_retention_period_section" style="' . $sec_display_style . '">
          <input type="number" class="form-control data_retention_period" placeholder="Data Retention Period In Days" value="' . $timePeriod . '">
        </div>
        </div>
      </div>';

        return '<fieldset style="border:1px solid #a6a5a8;padding:20px;width:100%;margin-bottom:20px;"><legend style="font-size:15px">&nbsp;Data Retention Policy </legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> Manage Data Retention Policy by Enable/Disable switch to delete unuseful data automatically</p>' . $Content . '</fieldset>';
    }
    //Start mapping Field Maker Functions
    public function getIdentityMapping(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $iconPath,
        $userId,
        $userIntegId,
        $sIdentityRule,
        $dIdentityRule,
        $sourceValidation,
        $destValidation,
        $sourcePltName,
        $destPltName,
        $slabel,
        $dlabel,
        $sfilterColumn,
        $dfilterColumn,
        $dfieldsetLabel,
        $dtooltipText
    ) {
        $selectVal = "";
        //set fieldset label
        if ($dfieldsetLabel) {
            $fieldsetLabelText = $dfieldsetLabel;
        } else {
            $fieldsetLabelText = "Define seller SKU (Required)";
        }
        //set tooltip text
        if ($dtooltipText) {
            $tooltipText = $dtooltipText;
        } else {
            $tooltipText = "Match the unique product identifier between the platforms. This value will be used by the integration to map the products";
        }

        $resObjData = $this->getPlatformObjectId('product_identity');
        $platform_object_id = $resObjData->id;
        $dynamicLabelText = $this->dynamicMappingLabel($sIdentityRule, $dIdentityRule, 'Unique Key');
        $sourceIdentCont = "";
        $destIdentCont = "";
        $IdentFieldsArr = [];

        if ($sIdentityRule == "ON") {
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            $uiDisplayStatus = "";
            $labelDisplayStatus = "";
            array_push($IdentFieldsArr, 'source');
            $sourceUnqIdent = DB::table('platform_fields')
                ->where('platform_object_id', $platform_object_id)
                ->where('platform_id', $sourcePlt)
                ->where('status', 1)
                ->select('id as optionId', 'description as optionValue')
                ->get();

            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);

            $sourceIdentCont .= '<div class="row text-center mx-0 mb-1 justify-content-center">
                <div class="col-xl-12 col-md-10 col-sm-12">
                    <div class="row align-items-center mappingFieldWrapper">';

            $sourceIdentCont .= '<div class="col col-md-5">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' Unique Product Identifier ' . $validationLabel . '</label>';
            if (count($sourceUnqIdent) == 1) {
                $uiDisplayStatus = "none";
                $labelDisplayStatus = "block";
                $sourceIdentCont .= '<span class="defaultMappingLabel" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $sourcePltName . ' Default Unique Product Identifier Selected" style="display:' . $labelDisplayStatus . '">';
                foreach ($sourceUnqIdent as $value) {
                    $sourceIdentCont .= $value->optionValue;
                }
                $sourceIdentCont .= '</span>';
            }


            $sourceIdentCont .= '<select class="form-control form-control-map b-1 unqIdentSource" id="unqIdentSource" alt=' . $platform_object_id . ' name=' . $pfwfrID . ' ' . $applyValidation . ' style="display:' . $uiDisplayStatus . '">';
            foreach ($sourceUnqIdent as $value) {
                $selectedIdent = DB::table('platform_data_mapping')
                    ->where('data_map_type', 'field')
                    ->where('platform_object_id', $platform_object_id)
                    ->where('platform_workflow_rule_id', $pfwfrID)
                    ->where('source_row_id', $value->optionId)
                    ->where('user_integration_id', $userIntegId)
                    ->first();
                if ($selectedIdent) {
                    if ($selectedIdent->source_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $sourceIdentCont .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
            }
            $sourceIdentCont .= '</select></div>';
        }
        if ($sIdentityRule == "ON" && $dIdentityRule == "ON") {
            $sourceIdentCont .= '<div class="col-md-1"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';
        }

        if ($dIdentityRule == "ON") {
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            $uiDisplayStatus = "";
            $labelDisplayStatus = "";
            array_push($IdentFieldsArr, 'destination');
            $destUnqIdent = DB::table('platform_fields')
                ->where('platform_object_id', $platform_object_id)
                ->where('platform_id', $destPlt)
                ->where('status', 1)
                ->select('id as optionId', 'description as optionValue')
                ->get();

            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);

            $destIdentCont .= '<div class="col col-md-5">
                    <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' Unique Product Identifier ' . $validationLabel . '</label>';
            if (count($destUnqIdent) == 1) {
                $uiDisplayStatus = "none";
                $labelDisplayStatus = "block";
                $destIdentCont .= '<span class="defaultMappingLabel" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $destPltName . ' Default Unique Product Identifier Selected" style="display:' . $labelDisplayStatus . '">';
                foreach ($destUnqIdent as $value) {
                    $destIdentCont .= $value->optionValue;
                }
                $destIdentCont .= '</span>';
            }

            $destIdentCont .= '<select class="form-control form-control-map b-1 unqIdentDest" alt=' . $platform_object_id . ' id="unqIdentDest" name=' . $pfwfrID . '  ' . $applyValidation . ' style="display:' . $uiDisplayStatus . '">';
            foreach ($destUnqIdent as $value) {
                $selectedIdent = DB::table('platform_data_mapping')
                    ->where('data_map_type', 'field')
                    ->where('platform_object_id', $platform_object_id)
                    ->where('platform_workflow_rule_id', $pfwfrID)
                    ->where('destination_row_id', $value->optionId)
                    ->where('user_integration_id', $userIntegId)
                    ->first();
                if ($selectedIdent) {
                    if ($selectedIdent->destination_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $destIdentCont .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . $value->optionValue . '</option>';
            }
            $destIdentCont .= '</select></div>';
            $destIdentCont .= '</div></div></div>';
        }

        return '<fieldset style="border:1px solid #a6a5a8;padding:20px;width:100%;margin-bottom:20px;"><legend style="font-size:15px">&nbsp;' . $fieldsetLabelText . '</legend><input type="hidden" value="1" id="MappingIndentFCount" placeholder="MappingIndentFCount">
        <p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>

        <textarea rows="4" cols="50" id="AvailIdentitySides" style="display:none">' . json_encode($IdentFieldsArr) . '</textarea>' . $sourceIdentCont . ' ' . $destIdentCont . '</fieldset>';
    }

    //Start mapping Field Maker Functions
    public function getIdentityMappingExtensive(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $iconPath,
        $userId,
        $userIntegId,
        $sIdentityRule,
        $dIdentityRule,
        $sourceValidation,
        $destValidation,
        $sourcePltName,
        $destPltName,
        $slabel,
        $dlabel,
        $sfilterColumn,
        $dfilterColumn,
        $dfieldsetLabel,
        $dtooltipText,
        $key
    ) {
        $selectVal = "";
        $formated_identity_map_key = str_replace("_", " ", $key);

        //set fieldset label
        if ($dfieldsetLabel) {
            $fieldsetLabelText = $dfieldsetLabel;
        } else {
            $fieldsetLabelText = ucfirst($formated_identity_map_key) . ' (Required)';
        }

        //set tooltip text
        if ($dtooltipText) {
            $tooltipText = $dtooltipText;
        } else {
            $tooltipText = "Match the unique " . $formated_identity_map_key . " between the platforms. This value will be used by the integration to map the customers";
        }

        $resObjData = $this->getPlatformObjectId($key);
        $platform_object_id = $resObjData->id;
        $dynamicLabelText = $this->dynamicMappingLabel($sIdentityRule, $dIdentityRule, 'Unique Key');
        $sourceIdentCont = "";
        $destIdentCont = "";
        $IdentFieldsArr = [];

        if ($sIdentityRule == "ON") {
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            $uiDisplayStatus = "";
            $labelDisplayStatus = "";
            array_push($IdentFieldsArr, 'source');
            $sourceUnqIdent = DB::table('platform_fields')
                ->where('platform_object_id', $platform_object_id)
                ->where('platform_id', $sourcePlt)
                ->where('status', 1)
                ->select('id as optionId', 'description as optionValue')
                ->get();

            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);

            $sourceIdentCont .= '<div class="row text-center mx-0 mb-1 justify-content-center">
                <div class="col-xl-12 col-md-10 col-sm-12">
                    <div class="row align-items-center mappingFieldWrapper">';

            $sourceIdentCont .= '<div class="col col-md-5">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' Unique ' . ucfirst($formated_identity_map_key) . $validationLabel . '</label>';
            if (count($sourceUnqIdent) == 1) {
                $uiDisplayStatus = "none";
                $labelDisplayStatus = "block";
                $sourceIdentCont .= '<span class="defaultMappingLabel" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $sourcePltName . ' Default Unique Customer Identifier Selected" style="display:' . $labelDisplayStatus . '">';
                foreach ($sourceUnqIdent as $value) {
                    $sourceIdentCont .= $value->optionValue;
                }
                $sourceIdentCont .= '</span>';
            }


            $sourceIdentCont .= '<select class="form-control form-control-map b-1 ' . $key . '_unqIdentSource" id="' . $key . '_unqIdentSource" alt=' . $platform_object_id . ' name=' . $pfwfrID . ' ' . $applyValidation . ' style="display:' . $uiDisplayStatus . '">';

            foreach ($sourceUnqIdent as $value) {
                $selectedIdent = DB::table('platform_data_mapping')
                    ->where('data_map_type', 'field')
                    ->where('platform_object_id', $platform_object_id)
                    ->where('platform_workflow_rule_id', $pfwfrID)
                    ->where('source_row_id', $value->optionId)
                    ->where('user_integration_id', $userIntegId)
                    ->first();
                if ($selectedIdent) {
                    if ($selectedIdent->source_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $sourceIdentCont .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
            }
            $sourceIdentCont .= '</select></div>';
        }
        if ($sIdentityRule == "ON" && $dIdentityRule == "ON") {
            $sourceIdentCont .= '<div class="col-md-1"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';
        }

        if ($dIdentityRule == "ON") {
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            $uiDisplayStatus = "";
            $labelDisplayStatus = "";
            array_push($IdentFieldsArr, 'destination');
            $destUnqIdent = DB::table('platform_fields')
                ->where('platform_object_id', $platform_object_id)
                ->where('platform_id', $destPlt)
                ->where('status', 1)
                ->select('id as optionId', 'description as optionValue')
                ->get();

            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);

            $destIdentCont .= '<div class="col col-md-5">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' Unique ' . ucfirst($formated_identity_map_key) . $validationLabel . '</label>';
            if (count($destUnqIdent) == 1) {
                $uiDisplayStatus = "none";
                $labelDisplayStatus = "block";
                $destIdentCont .= '<span class="defaultMappingLabel" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $destPltName . ' Default Unique Customer Identifier Selected" style="display:' . $labelDisplayStatus . '">';
                foreach ($destUnqIdent as $value) {
                    $destIdentCont .= $value->optionValue;
                }
                $destIdentCont .= '</span>';
            }

            $destIdentCont .= '<select class="form-control form-control-map b-1 ' . $key . '_unqIdentDest" alt=' . $platform_object_id . ' id="' . $key . '_unqIdentDest" name=' . $pfwfrID . '  ' . $applyValidation . ' style="display:' . $uiDisplayStatus . '">';
            foreach ($destUnqIdent as $value) {
                $selectedIdent = DB::table('platform_data_mapping')
                    ->where('data_map_type', 'field')
                    ->where('platform_object_id', $platform_object_id)
                    ->where('platform_workflow_rule_id', $pfwfrID)
                    ->where('destination_row_id', $value->optionId)
                    ->where('user_integration_id', $userIntegId)
                    ->first();
                if ($selectedIdent) {
                    if ($selectedIdent->destination_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $destIdentCont .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . $value->optionValue . '</option>';
            }
            $destIdentCont .= '</select></div>';
            $destIdentCont .= '</div></div></div>';
        }

        return '<fieldset style="border:1px solid #a6a5a8;padding:20px;width:100%;margin-bottom:20px;"><legend style="font-size:15px">&nbsp;' . $fieldsetLabelText . '</legend><input type="hidden" value="1" id="Mapping_' . $key . '_Count" placeholder="Mapping_' . $key . '_Count">
        <p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>
        <textarea rows="4" cols="50" id="Mapping_' . $key . '_Sides" style="display:none">' . json_encode($IdentFieldsArr) . '</textarea>' . $sourceIdentCont . ' ' . $destIdentCont . '</fieldset>';
    }

    
    //Multi select single side mapping generator for status & default_inventory_warehouse_ms & rest
    public function getMultiSelectMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $pobjName, $sRule, $dRule, $sourceValidation, $destValidation, $slabel, $dlabel, $sfilterColumn, $dfilterColumn, $slabelTooltip, $dlabelTooltip, $key_with_wfId, $mh_switch_objId)
    {
        $selectVal = "";
        $selectedPlt = "";
        $getOsData = $this->getPlatformObjectId($pobjName);
        $linked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        if ($getOsData->store_with_id) {
            $new_platform_object_id = $getOsData->store_with_id;
        } else {
            $new_platform_object_id = $getOsData->id;
        }
        //linked object for pullout mapping data
        $platform_object_id = $getOsData->linked_with_id;
        $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $pobjName);

        $sourceContent = "";
        $destContent = "";
        $FieldsArr = [];

        if ($sRule == "ON") {
            //set tooltipFor Label
            $labelTooltipHtml = "";
            if ($slabelTooltip != "" || $slabelTooltip != NULL) {
                $labelTooltipHtml = '&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;" data-original-title="' . $slabelTooltip . '"></i>';
            }

            $selectedPlt = $sourcePltName;
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }
            array_push($FieldsArr, 'source');

            //load data based on object
            if ($linked_table == "platform_fields") {
                $filterData = ($sfilterColumn != "custom_default") ? [$sfilterColumn] : ['custom', 'default'];
                $dynEditId = $this->getInsertId($new_platform_object_id, 'field', $userIntegId, $pfwfrID, $pobjName);
                $dynEditId ? $editId = $dynEditId->id : $editId = '';
                $sourceData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName, $filterData);
                $data_map_type = "field";
            } else {

                $dynEditId = $this->getInsertId($new_platform_object_id, 'object', $userIntegId, $pfwfrID, $pobjName);
                $dynEditId ? $editId = $dynEditId->id : $editId = '';

                $sourceData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                $data_map_type = "object";
            }

            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);

            $sourceContent .= '<div class="col-md-12" style="margin-bottom:30px;">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $dynamicLabelText . ' ' . $validationLabel . $labelTooltipHtml . '</label>';
            $sourceContent .= '<select multiple data-placeholder="' . $dynamicLabelText . '" data-allow-clear="1" class="b-1 ' . $key_with_wfId . '_sourceSelect" name="' . $pfwfrID . '" alt=' . $new_platform_object_id . '
                data-data_map_type="' . $data_map_type . '" ' . $applyValidation . '>';
            //<option value="">Select '.$dynamicLabelText.'</option>
            foreach ($sourceData as $value) {

                if ($pobjName == "default_inventory_warehouse_ms" || $pobjName == "default_order_warehouse") {
                    //check multi warehouse Switch Status in mapping
                    $multiWhStatus = DB::table('platform_data_mapping')->select('custom_data')->where('user_integration_id', $userIntegId)
                        ->where('mapping_type', 'default')->where('data_map_type', 'custom')->where('platform_object_id', $mh_switch_objId)->where('status', '1')->pluck('custom_data')->first();

                    if (isset($multiWhStatus) && ($multiWhStatus == 1)) {
                        $selectedIndex = "";
                    } else {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'source_row_id', $value->optionId, $userIntegId, $pfwfrID, $data_map_type, 'regular');
                    }
                } else {
                    $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'source_row_id', $value->optionId, $userIntegId, $pfwfrID, $data_map_type, 'regular');
                }

                if ($selectedIndex) {
                    if ($selectedIndex->source_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $sourceContent .= '<option name="' . $editId . '" value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
            }
            $sourceContent .= '</select>';
            $sourceContent .= '</div>';
        }
        if ($dRule == "ON") {
            //set tooltipFor Label
            $labelTooltipHtml = "";
            if ($dlabelTooltip != "" || $dlabelTooltip != NULL) {
                $labelTooltipHtml = '&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;" data-original-title="' . $dlabelTooltip . '"></i>';
            }

            $selectedPlt = $destPltName;
            if ($dlabel) {
                $dynamicLabelText = $dlabel;
            }
            array_push($FieldsArr, 'destination');
            //load data based on object
            if ($linked_table == "platform_fields") {
                $filterData = ($dfilterColumn != "custom_default") ? [$dfilterColumn] : ['custom', 'default'];
                $dynEditId = $this->getInsertId($new_platform_object_id, 'field', $userIntegId, $pfwfrID, $pobjName);
                $dynEditId ? $editId = $dynEditId->id : $editId = '';
                $destData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName, $filterData);
                $data_map_type = "field";
            } else {
                $dynEditId = $this->getInsertId($new_platform_object_id, 'object', $userIntegId, $pfwfrID, $pobjName);
                $dynEditId ? $editId = $dynEditId->id : $editId = '';
                $destData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                $data_map_type = "object";
            }

            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);

            $destContent .= '<div class="col-md-12" style="margin-bottom:30px;">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $dynamicLabelText . ' ' . $validationLabel
                . $labelTooltipHtml . '</label>';
            $destContent .= '<select  multiple data-placeholder="' . $dynamicLabelText . '" data-allow-clear="1" class="b-1 ' . $key_with_wfId . '_destSelect" name="' . $pfwfrID . '" alt=' . $new_platform_object_id . '
                data-data_map_type="' . $data_map_type . '" ' . $applyValidation . '>';
            // <option value="">Select '.$dynamicLabelText.'</option>
            foreach ($destData as $value) {
                if ($pobjName == "default_inventory_warehouse_ms" || $pobjName == "default_order_warehouse") {
                    //check multi warehouse Switch Status in mapping
                    $multiWhStatus = DB::table('platform_data_mapping')->select('custom_data')->where('user_integration_id', $userIntegId)
                        ->where('mapping_type', 'default')->where('data_map_type', 'custom')->where('platform_object_id', $mh_switch_objId)->where('status', '1')->pluck('custom_data')->first();

                    if (isset($multiWhStatus) && ($multiWhStatus == 1)) {
                        $selectedIndex = "";
                    } else {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'destination_row_id', $value->optionId, $userIntegId, $pfwfrID, $data_map_type, 'regular');
                    }
                } else {
                    $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'destination_row_id', $value->optionId, $userIntegId, $pfwfrID, $data_map_type, 'regular');
                }



                if ($selectedIndex) {
                    if ($selectedIndex->destination_row_id == $value->optionId) {
                        $selectVal = "selected";
                    }
                } else {
                    $selectVal = "";
                }
                $destContent .= '<option name="' . $editId . '" value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
            }
            $destContent .= '</select>';
            $destContent .= '</div>';
        }
        if ($sRule == "ON" && $dRule == "ON") {
            $mapping_type = "regular";
            $data = '<div class="row"><div class="col-md-12" style="display:flex"><textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($FieldsArr) . '</textarea>' . $sourceContent . ' ' . $destContent . '</div></div>';
        } else {
            $mapping_type = "default";
            $data = '<textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($FieldsArr) . '</textarea>' . $sourceContent . ' ' . $destContent;
        }
        return ['data' => $data, 'mapping_type' => $mapping_type, 'count' => 1, 'selectedPlt' => $selectedPlt];
    }
    //Order Syn Start Date
    public function getOrderSyncStart(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $slabel,
        $dlabel,
        $pobjName,
        $iconPath,
        $key_with_wfId
    ) {
        $resObjData = $this->getPlatformObjectId($pobjName);
        $platform_object_id = $resObjData->id;

        $OrderSyncStart = "";

        $dataUwfr = DB::table('user_workflow_rule')
            ->select('sync_start_date', 'is_all_data_fetched')
            ->where('user_integration_id', $userIntegId)
            ->where('platform_workflow_rule_id', $pfwfrID)
            ->where('user_id', $userId)
            ->first();

        if ($dataUwfr) {
            $defaultVal = $dataUwfr->sync_start_date;
        } else {
            $defaultVal = "";
        }

        $disabledText = ($defaultVal && $dataUwfr->is_all_data_fetched == "completed") ? 'disabled' : '';
        $dynStyle = ($defaultVal && $dataUwfr->is_all_data_fetched == "completed") ? '#f3f2f7' : 'white';


        if ($sRule == "ON") {
            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);
        } else {
            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);
        }

        $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $pobjName);
        if ($slabel) {
            $dynamicLabelText = $slabel;
        }
        if ($dlabel) {
            $dynamicLabelText = $dlabel;
        }

        $OrderSyncStart .= '<div class="col-md-12 form-group">
            <label for="fp-date-time"  style="font-weight:bold;">' . $dynamicLabelText . ' ' . $validationLabel . '</label>
            <i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;"
            title="Date will get stored only one time, can not be edited later if you want to update the date, then please disconnect and reconnect the configuration"></i>
            <input
            style="background-color:' . $dynStyle . '"
              type="text"
              value="' . $defaultVal . '"
              class="form-control form-control-map flatpickr-date-time ' . $key_with_wfId . '" data-wfrId=' . $pfwfrID . '
              placeholder="YYYY-MM-DD HH:MM in UTC" alt=' . $platform_object_id . '
              ' . $applyValidation . '   ' . $disabledText . '
            />
          </div>';
        //id="fp-date-time"
        return $OrderSyncStart;
    }
    //single side or default mappings generator
    public function makeMappingObjectHtml(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $pobjName,
        $data_map_type,
        $source_input_type,
        $dest_input_type,
        $slabel,
        $dlabel,
        $sfilterColumn,
        $dfilterColumn,
        $slabelTooltip,
        $dlabelTooltip,
        $key_with_wfId
    ) {
        $selectVal = "";
        $getOsData = $this->getPlatformObjectId($pobjName);
        $linked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        if ($getOsData->store_with_id) {
            $new_platform_object_id = $getOsData->store_with_id;
        } else {
            $new_platform_object_id = $getOsData->id;
        }

        //set select width
        if ($pobjName == "warehouse_plugins") {
            $dynSelWidth = "col-md-12";
            $dynExtraClass = " warehouse_plugins";
        } else {
            $dynSelWidth = "col-md-6";
            $dynExtraClass = "";
        }

        //linked object for pullout mapping data
        $platform_object_id = $getOsData->linked_with_id;
        $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $pobjName);

        if ($pobjName == 'timezone') {
            $dynEditId = $this->getInsertId($new_platform_object_id, 'timezone', $userIntegId, $pfwfrID, $pobjName);
        } else {
            $dynEditId = $this->getInsertId($new_platform_object_id, $data_map_type, $userIntegId, $pfwfrID, $pobjName);
        }
        $dynEditId ? $editId = $dynEditId->id : $editId = '';

        $sourceContent = "";
        $destContent = "";
        $FieldsArr = [];

        if ($sRule == "ON") {
            //set tooltipFor Label
            $labelTooltipHtml = "";
            if ($slabelTooltip != "" || $slabelTooltip != NULL) {
                $labelTooltipHtml = '&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;" data-original-title="' . $slabelTooltip . '"></i>';
            }

            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            array_push($FieldsArr, 'source');
            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);

            $sourceContent .= '<div class="' . $dynSelWidth . $dynExtraClass . '" style="margin-bottom:30px;">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . $labelTooltipHtml . '</label>';

            ////input_type number of text in mapping rules
            if ($source_input_type == "number" || $source_input_type == "text" || $source_input_type == "email") {
                $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'mapping_type', 'default', $userIntegId, $pfwfrID, 'custom', 'default');
                if ($selectedIndex) {
                    $selCustom_data = $selectedIndex->custom_data;
                    $selEditId = $selectedIndex->id;
                } else {
                    $selCustom_data = "";
                    $selEditId = "";
                }

                $sourceContent .= '<input type="' . $source_input_type . '" value="' . $selCustom_data . '"  class="form-control form-control-map ' . $key_with_wfId . '_sourceSelect" placeholder="' . $dynamicLabelText . '"
                data-data_map_type="custom" data-editId="' . $selEditId . '" name="' . $pfwfrID . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . ' />';
            }
            //default input_type select list for all remain mappings
            else {
                if ($linked_table == "platform_fields") {
                    $filterData = ($sfilterColumn != "custom_default") ? [$sfilterColumn] : ['custom', 'default'];
                    $sourceData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else if ($linked_table == "es_timezone") {
                    $sourceData = $this->getTimezoneData();
                    $data_map_type = "timezone";
                } else {
                    $sourceData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                    $data_map_type = "object";
                }
                $sourceContent .= '<select class="form-control form-control-map b-1 ' . $key_with_wfId . '_sourceSelect" name="' . $pfwfrID . '" data-editId="' . $editId . '" alt=' . $new_platform_object_id . '
                data-data_map_type="' . $data_map_type . '" ' . $applyValidation . '>
                <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($sourceData as $value) {
                    if ($data_map_type == "timezone") {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'custom_data', $value->optionId, $userIntegId, $pfwfrID, 'timezone', 'default');
                    } else {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'source_row_id', $value->optionId, $userIntegId, $pfwfrID, 'object', 'default');
                    }
                    if ($selectedIndex) {
                        $old_record_id = ($data_map_type == "timezone") ? $selectedIndex->custom_data : $selectedIndex->source_row_id;
                        if ($old_record_id == $value->optionId) {
                            $selectVal = "selected";
                        }
                    } else {
                        $selectVal = "";
                    }
                    $sourceContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                }
                $sourceContent .= '</select>';
            }


            $sourceContent .= '</div>';
        }
        //<div class="col-md-1"></div>

        if ($dRule == "ON") {
            //set tooltipFor Label
            $labelTooltipHtml = "";
            if ($dlabelTooltip != "" || $dlabelTooltip != NULL) {
                $labelTooltipHtml = '&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;" data-original-title="' . $dlabelTooltip . '"></i>';
            }

            if ($dlabel) {
                $dynamicLabelText = $dlabel;
            }
            array_push($FieldsArr, 'destination');
            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);

            $destContent .= '<div class="' . $dynSelWidth . $dynExtraClass . '" style="margin-bottom:30px;">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . $labelTooltipHtml . '</label>';
            //custom type fields
            if ($dest_input_type == "number" || $dest_input_type == "text" || $dest_input_type == "email") {
                $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'mapping_type', 'default', $userIntegId, $pfwfrID, 'custom', 'default');
                if ($selectedIndex) {
                    $selCustom_data = $selectedIndex->custom_data;
                    $selEditId = $selectedIndex->id;
                } else {
                    $selCustom_data = "";
                    $selEditId = "";
                }

                $destContent .= '<input type="' . $dest_input_type . '" value="' . $selCustom_data . '" id="' . $key_with_wfId . '_destSelect" class="form-control form-control-map ' . $key_with_wfId . '_destSelect" placeholder="' . $dynamicLabelText . '"
                data-data_map_type="custom" data-editId="' . $selEditId . '" name="' . $pfwfrID . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . ' />';
            }
            //default input_type select list for all remain mappings
            else {
                if ($linked_table == "platform_fields") {
                    $filterData = ($dfilterColumn != "custom_default") ? [$dfilterColumn] : ['custom', 'default'];
                    $destData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else if ($linked_table == "es_timezone") {
                    $destData = $this->getTimezoneData();
                    $data_map_type = "timezone";
                } else {
                    $destData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                    $data_map_type = "object";
                }
                $destContent .= '<select class="form-control form-control-map b-1 ' . $key_with_wfId . '_destSelect" name="' . $pfwfrID . '" data-editId="' . $editId . '" alt=' . $new_platform_object_id . '
                data-data_map_type="' . $data_map_type . '" ' . $applyValidation . '>
                <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($destData as $value) {
                    if ($data_map_type == "timezone") {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'custom_data', $value->optionId, $userIntegId, $pfwfrID, 'timezone', 'default');
                    } else {
                        $selectedIndex = $this->getSelectedIndex($new_platform_object_id, 'destination_row_id', $value->optionId, $userIntegId, $pfwfrID, 'object', 'default');
                    }
                    if ($selectedIndex) {
                        $old_record_id = ($data_map_type == "timezone") ? $selectedIndex->custom_data : $selectedIndex->destination_row_id;
                        if ($old_record_id == $value->optionId) {
                            $selectVal = "selected";
                        }
                    } else {
                        $selectVal = "";
                    }
                    $destContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                }
                $destContent .= '</select>';
            }
            //<div class="col-md-1"></div>
            $destContent .= '</div>';
        }
        if ($sRule == "ON" && $dRule == "ON") {
            $mapping_type = "regular";
            $data = '<div class="row"><div class="col-md-12" style="display:flex"><textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($FieldsArr) . '</textarea>' . $sourceContent . ' ' . $destContent . '</div></div>';
        } else {
            $mapping_type = "default";
            $data = '<textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($FieldsArr) . '</textarea>' . $sourceContent . ' ' . $destContent;
        }
        return ['data' => $data, 'mapping_type' => $mapping_type, 'count' => 1, 'selMapLabel' => $dynamicLabelText];
    }



    //one 2 one mapping generator with + - options
    public function getManytoManyMapping(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $pobjName,
        $data_map_type,
        $iconPath,
        $slabel,
        $dlabel,
        $source_inputType,
        $dest_inputType,
        $sfilterColumn,
        $dfilterColumn,
        $key_with_wfId
    ) {
        $selectVal = "";
        //set dynamic field width for depdrop
        $dynFieldWidth = ($source_inputType == "depDrop" || $dest_inputType == "depDrop") ? 'col-md-4' : 'col-md-5';

        $dynamicLabelText = "";
        $getOsData = $this->getPlatformObjectId($pobjName);
        $new_platform_object_id = $getOsData->id;
        //linked object for pullout mapping data
        $platform_object_id = $getOsData->linked_with_id;

        $linked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        $custObjName = "'" . $key_with_wfId . "'";
        //check inserted Warehouse Data
        //dummy logic fix it latter
        $mappingDataArr_query = DB::table('platform_data_mapping')
            //->where('data_map_type',$data_map_type)
            ->where('platform_object_id', $new_platform_object_id)
            ->where('platform_workflow_rule_id', $pfwfrID)
            ->where('user_integration_id', $userIntegId)
            ->where('mapping_type', 'regular');

        if (($sourcePltName == "Brightpearl" && $destPltName == "WooCommerce") || ($sourcePltName == "WooCommerce" && $destPltName == "Brightpearl")) {
            if ($source_inputType == "select_list" && $dest_inputType == "select_list") {
                $mappingDataArr_query->whereNotNull('source_row_id')
                    ->whereNotNull('destination_row_id')
                    ->where('source_row_id', '!=', '')
                    ->where('destination_row_id', '!=', '');
            }
        }

        $mappingDataArr = $mappingDataArr_query->where('status', 1)
            ->get();

        if (count($mappingDataArr) > 0) {
            $dynamicMappingContents = "";
            $i = 0;
            foreach ($mappingDataArr as $whData) {
                $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $pobjName);
                $sourceContent = "";
                $destContent = "";
                $availSideArr = [];
                if ($i == 0) {
                    $dynamicClass = $key_with_wfId . "_D_MainItem";
                    $i = "";
                } else {
                    $dynamicClass = $key_with_wfId . "_DItem " . $key_with_wfId . "_Clone" . $i;
                }
                //show fresh wh
                $sourceContent .= '<div class="row text-center mx-0 mb-1 justify-content-center ' . $dynamicClass . '">
                <div class="col-xl-12 col-md-10 col-sm-12">
                <div class="row align-items-center ' . $key_with_wfId . '_mappingFieldWrapper">';
                if ($sRule == "ON") {
                    if ($slabel) {
                        $dynamicLabelText = $slabel;
                    }
                    array_push($availSideArr, 'source');
                    $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
                    $validationLabel = $this->getValidationText($sourceValidation);

                    if ($source_inputType == "number" || $source_inputType == "text" || $source_inputType == "email") {
                        $sourceContent .= '<div class="col ' . $dynFieldWidth . '"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                        $sourceContent .= '<input type="' . $source_inputType . '" value="' . $whData->custom_data . '"  class="form-control form-control-map ' . $key_with_wfId . '_sourceSelect' . $i . '" placeholder="' . $dynamicLabelText . '"
                        name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . ' />';
                        $sourceContent .= '</div>';
                    } else if (($source_inputType == "depDrop") && ($pobjName == "porder_shipping_method" || $pobjName == "sorder_shipping_method")) {
                        //get zone id to get its data
                        $obj_data = $this->getPlatformObjectIdFromDb('zone');
                        $zone_obj_id = $obj_data->id;
                        $zoneLabel = ($obj_data->display_name) ? $obj_data->display_name : 'Select Zone';

                        //get select parent
                        $parentData = DB::table('platform_object_data')->select('parent_id')->where('id', $whData->source_row_id)->first();
                        $parent_id = ($parentData) ? $parentData->parent_id : null;

                        //get zone data
                        $zone_mapObjData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $zone_obj_id);
                        $sourceContent .= '<div class="col col-md-3">
                        <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $zoneLabel . ' ' . $validationLabel . '</label><select class="form-control b-1 zone_selection" onChange="loadDepDropData(this,1)" data-wfrId=' . $pfwfrID . ' data-pltObjId=' . $platform_object_id . ' data-plt="' . $sourcePltName . '" data-pltId="' . $sourcePlt . '" data-loadObj="' . $key_with_wfId . '_sourceSelect">
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($zone_mapObjData as $value) {
                            if ($parent_id == $value->optionId) {
                                $selectVal = "selected";
                            } else {
                                $selectVal = "";
                            }
                            $sourceContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                        }
                        $sourceContent .= '</select></div>';

                        $sourceContent .= '<div class="col col-md-3"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                        $sourceObjectData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName, $parent_id);
                        $sourceContent .= '<select class="form-control form-control-map b-1 source_depDrop ' . $key_with_wfId . '_sourceSelect' . $i . '" name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . '>
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($sourceObjectData as $value) {
                            if ($whData->source_row_id == $value->optionId) {
                                $selectVal = "selected";
                            } else {
                                $selectVal = "";
                            }
                            $sourceContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                        }
                        $sourceContent .= '</select>';
                        $sourceContent .= '</div>';
                    } else {
                        $sourceContent .= '<div class="col ' . $dynFieldWidth . '"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

                        //new logic for support one to one mapping for field objects
                        if ($linked_table == "platform_fields") {
                            $sourceObjectData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                        } else {
                            $sourceObjectData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                        }

                        $sourceContent .= '<select class="form-control form-control-map b-1 ' . $key_with_wfId . '_sourceSelect' . $i . '" name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . '  data-linked_table="' . $linked_table . '" ' . $applyValidation . '>
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($sourceObjectData as $value) {
                            if (($value->optionId && $value->optionValue) != "")
                                if ($whData->source_row_id == $value->optionId) {
                                    $selectVal = "selected";
                                } else {
                                    $selectVal = null;
                                }
                            $sourceContent .= '<option ' . $selectVal . ' value="' . $value->optionId . '">' . strip_tags($value->optionValue) . '</option>';
                        }
                        $sourceContent .= '</select>';
                        $sourceContent .= '</div>';
                    }
                }

                if ($sRule == "ON" && $dRule == "ON") {
                    $sourceContent .= '<div class="col-md-1"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';
                }
                if ($dRule == "ON") {
                    if ($dlabel) {
                        $dynamicLabelText = $dlabel;
                    }
                    array_push($availSideArr, 'destination');
                    //Start Dest data
                    $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
                    $validationLabel = $this->getValidationText($destValidation);

                    if ($dest_inputType == "number" || $dest_inputType == "text" || $dest_inputType == "email") {
                        $destContent .= '<div class="col ' . $dynFieldWidth . '"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                        $destContent .= '<input type="' . $dest_inputType . '" value="' . $whData->custom_data . '"  class="form-control form-control-map ' . $key_with_wfId . '_destSelect' . $i . '" placeholder="' . $dynamicLabelText . '"
                        name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . ' />';
                        $destContent .= '</div>';
                    } else if (($dest_inputType == "depDrop") && ($pobjName == "porder_shipping_method" || $pobjName == "sorder_shipping_method")) {
                        //get zone id to get its data
                        $obj_data = $this->getPlatformObjectIdFromDb('zone');
                        $zone_obj_id = $obj_data->id;
                        $zoneLabel = ($obj_data->display_name) ? $obj_data->display_name : 'Select Zone';

                        //get select parent
                        $parentData = DB::table('platform_object_data')->select('parent_id')->where('id', $whData->destination_row_id)->first();
                        $parent_id = ($parentData) ? $parentData->parent_id : null;

                        //get zone data
                        $zone_mapObjData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $zone_obj_id);
                        $destContent .= '<div class="col col-md-3">
                        <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $zoneLabel . ' ' . $validationLabel . '</label><select class="form-control b-1 zone_selection" onChange="loadDepDropData(this,2)" data-wfrId=' . $pfwfrID . ' data-pltObjId=' . $platform_object_id . ' data-plt="' . $destPltName . '" data-pltId="' . $destPlt . '" data-loadObj="' . $key_with_wfId . '_destSelect">
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($zone_mapObjData as $value) {
                            if ($parent_id == $value->optionId) {
                                $selectVal = "selected";
                            } else {
                                $selectVal = "";
                            }
                            $destContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . $value->optionValue . '</option>';
                        }
                        $destContent .= '</select></div>';

                        $destContent .= '<div class="col col-md-3"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                        $destObjectData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName, $parent_id);
                        $destContent .= '<select  class="form-control form-control-map b-1 dest_depDrop ' . $key_with_wfId . '_destSelect' . $i . '" name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . '>
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($destObjectData as $value) {
                            if ($whData->destination_row_id == $value->optionId) {
                                $selectVal = "selected";
                            } else {
                                $selectVal = "";
                            }
                            $destContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                        }
                        $destContent .= '</select>';
                        $destContent .= '</div>';
                    } else {
                        $destContent .= '<div class="col ' . $dynFieldWidth . '"><label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

                        //new logic for support one to one mapping for field objects
                        if ($linked_table == "platform_fields") {
                            $destObjectData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                        } else {
                            $destObjectData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                        }



                        $destContent .= '<select  class="form-control form-control-map b-1 ' . $key_with_wfId . '_destSelect' . $i . '" name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . '  data-linked_table="' . $linked_table . '" '  . $applyValidation . '>
                        <option value="">Select ' . $dynamicLabelText . '</option>';
                        foreach ($destObjectData as $value) {
                            if ($whData->destination_row_id == $value->optionId) {
                                $selectVal = "selected";
                            } else {
                                $selectVal = "";
                            }
                            $destContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                        }
                        $destContent .= '</select>';
                        $destContent .= '</div>';
                    }
                }

                if ($sRule == "ON" && $dRule == "ON") {
                    if ($i == 0) {
                        $destContent .= '<div class="row"><div class="col-md-1 addMappingField" onClick="addwhFields(' . $custObjName . ')" style="display:none;margin-top:15px;margin-left:25px"><i class="fa fa-plus" aria-hidden="true"></i></div>';
                        $destContent .= '<div class="col-md-1 removeMappingField" style="display:none;margin-top:15px;margin-left:25px" onClick="removeWhField(this,' . $custObjName . ')"><i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div></div>';
                    } else {
                        $destContent .= '<div class="row"><div class="col-md-1 addMappingField" style="display:none;margin-top:15px;margin-left:25px" onClick="addwhFields(' . $custObjName . ')"><i class="fa fa-plus" aria-hidden="true"></i></div>';
                        $destContent .= '<div class="col-md-1 removeMappingField" onClick="removeWhField(this,' . $custObjName . ')" style="margin-top:15px;margin-left:25px">
                        <i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div></div>';
                    }
                }

                $destContent .= '</div></div></div>';

                $dynamicMappingContents .= '<div class="row"><div class="col-md-12"><textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($availSideArr) . '</textarea>' . $sourceContent . ' ' . $destContent . '</div></div>';
                $i++;
            }
            return ['data' => $dynamicMappingContents, 'count' => count($mappingDataArr)];
        } else {
            $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $pobjName);
            //load fresh warehouse
            $sourceContent = "";
            $destContent = "";
            $i = 0;
            $availSideArr = [];
            $sourceContent .= '<div class="row text-center mx-0 mb-1 justify-content-center ' . $key_with_wfId . '_D_MainItem">
            <div class="col-xl-12 col-md-10 col-sm-12">
            <div class="row align-items-center ' . $key_with_wfId . '_mappingFieldWrapper">';

            if ($sRule == "ON") {
                if ($slabel) {
                    $dynamicLabelText = $slabel;
                }
                array_push($availSideArr, 'source');

                //new logic for support one to one mapping for field objects
                if ($linked_table == "platform_fields") {
                    $sourceObjectData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                } else {
                    $sourceObjectData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                }


                $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
                $validationLabel = $this->getValidationText($sourceValidation);

                if ($source_inputType == "number" || $source_inputType == "text" || $source_inputType == "email") {
                    $sourceContent .= '<div class="col ' . $dynFieldWidth . '">
                    <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                    $sourceContent .= '<input type="' . $source_inputType . '" value=""  class="form-control form-control-map ' . $key_with_wfId . '_sourceSelect" placeholder="' . $dynamicLabelText . '"
                    name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' ' . $applyValidation . ' />';
                    $sourceContent .= '</div>';
                } else if (($source_inputType == "depDrop") && ($pobjName == "porder_shipping_method" || $pobjName == "sorder_shipping_method")) {
                    //get zone id to get its data
                    $obj_data = $this->getPlatformObjectIdFromDb('zone');
                    $zone_obj_id = $obj_data->id;
                    $zoneLabel = ($obj_data->display_name) ? $obj_data->display_name : 'Select Zone';

                    //get zone data
                    $zone_mapObjData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $zone_obj_id);
                    $sourceContent .= '<div class="col col-md-3">
                   <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $zoneLabel . ' ' . $validationLabel . '</label><select class="form-control b-1 zone_selection" onChange="loadDepDropData(this,1)" data-wfrId=' . $pfwfrID . ' data-pltObjId=' . $platform_object_id . ' data-plt="' . $sourcePltName . '" data-pltId="' . $sourcePlt . '" data-loadObj="' . $key_with_wfId . '_sourceSelect">
                   <option value="">Select ' . $dynamicLabelText . '</option>';
                    foreach ($zone_mapObjData as $value) {
                        $sourceContent .= '<option value="' . $value->optionId . '">' . strip_tags($value->optionValue) . '</option>';
                    }
                    $sourceContent .= '</select></div>';

                    //same as other one to one dest side but changes with width col-md-3
                    $sourceContent .= '<div class="col col-md-3">
                   <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                    $sourceContent .= '<select class="form-control form-control-map b-1 source_depDrop ' . $key_with_wfId . '_sourceSelect" name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' ' . $applyValidation . '>
                   <option value="">Select ' . $dynamicLabelText . '</option>';
                    $sourceContent .= '</select>';
                    $sourceContent .= '</div>';
                } else {

                    $sourceContent .= '<div class="col ' . $dynFieldWidth . '">
                    <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                    $sourceContent .= '<select class="form-control form-control-map b-1 ' . $key_with_wfId . '_sourceSelect" name="' . $pfwfrID . '" data-editId="" 
                    alt=' . $new_platform_object_id . ' data-linked_table="' . $linked_table . '"  ' . $applyValidation . '>
                    <option value="">Select ' . $dynamicLabelText . '</option>';
                    foreach ($sourceObjectData as $value) {
                        $sourceContent .= '<option value=' . $value->optionId . '>' . strip_tags($value->optionValue) . '</option>';
                    }

                    $sourceContent .= '</select>';
                    $sourceContent .= '</div>';
                }
            }
            if ($sRule == "ON" && $dRule == "ON") {
                $sourceContent .= '<div class="col-md-1"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';
            }
            if ($dRule == "ON") {
                if ($dlabel) {
                    $dynamicLabelText = $dlabel;
                }
                array_push($availSideArr, 'destination');

                //new logic for support one to one mapping for field objects
                if ($linked_table == "platform_fields") {
                    $destObjectData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                } else {
                    $destObjectData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                }

                $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
                $validationLabel = $this->getValidationText($destValidation);

                if ($dest_inputType == "number" || $dest_inputType == "text" || $dest_inputType == "email") {
                    $destContent .= '<div class="col ' . $dynFieldWidth . '" >
                    <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                    $destContent .= '<input type="' . $dest_inputType . '" value=""  class="form-control form-control-map ' . $key_with_wfId . '_destSelect" placeholder="' . $dynamicLabelText . '"
                    name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="object" ' . $applyValidation . '/>';
                    $destContent .= '</div>';
                } else if (($dest_inputType == "depDrop") && ($pobjName == "porder_shipping_method" || $pobjName == "sorder_shipping_method")) {
                    //get zone id to get its data
                    $obj_data = $this->getPlatformObjectIdFromDb('zone');
                    $zone_obj_id = $obj_data->id;
                    $zoneLabel = ($obj_data->display_name) ? $obj_data->display_name : 'Select Zone';

                    //get zone data
                    $zone_mapObjData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $zone_obj_id);
                    $destContent .= '<div class="col col-md-3">
                   <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $zoneLabel . ' ' . $validationLabel . '</label><select class="form-control form-control-map b-1 zone_selection" onChange="loadDepDropData(this,2)" data-wfrId=' . $pfwfrID . ' data-pltObjId=' . $platform_object_id . ' data-plt="' . $destPltName . '" data-pltId="' . $destPlt . '" data-loadObj="' . $key_with_wfId . '_destSelect">
                   <option value="">Select ' . $dynamicLabelText . '</option>';
                    foreach ($zone_mapObjData as $value) {
                        $destContent .= '<option value="' . $value->optionId . '">' . strip_tags($value->optionValue) . '</option>';
                    }
                    $destContent .= '</select></div>';

                    //same as other one to one dest side but changes with width col-md-3
                    $destContent .= '<div class="col col-md-3">
                   <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

                    //pobjName where using instead key_with_wfId
                    $destContent .= '<select class="form-control b-1 dest_depDrop ' . $key_with_wfId . '_destSelect" name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' ' . $applyValidation . '>
                   <option value="">Select ' . $dynamicLabelText . '</option>';
                    $destContent .= '</select>';
                    $destContent .= '</div>';
                } else {
                    $destContent .= '<div class="col ' . $dynFieldWidth . '" >
                    <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';
                    $destContent .= '<select class="form-control form-control-map b-1 ' . $key_with_wfId . '_destSelect" name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="object" ' . ' data-linked_table="' . $linked_table . '"  ' . $applyValidation . '>
                    <option value="">Select ' . $dynamicLabelText . '</option>';
                    foreach ($destObjectData as $value) {
                        $destContent .= '<option value="' . $value->optionId . '">' . strip_tags($value->optionValue) . '</option>';
                    }
                    $destContent .= '</select>';
                    $destContent .= '</div>';
                }
            }
            if ($sRule == "ON" && $dRule == "ON") {
                if ($i == 0) {
                    $destContent .= '<div class="row"><div class="col-md-1 addMappingField" onClick="addwhFields(' . $custObjName . ')" style="display:none;margin-top:15px;margin-left:25px"><i class="fa fa-plus" aria-hidden="true"></i></div>';
                    $destContent .= '<div class="col-md-1 removeMappingField" style="display:none;margin-top:15px;margin-left:25px" onClick="removeWhField(this,' . $custObjName . ')"><i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div></div>';
                } else {
                    $destContent .= '<div class="row"><div class="col-md-1 addMappingField" style="display:none;margin-top:15px;margin-left:25px" onClick="addwhFields(' . $custObjName . ')"><i class="fa fa-plus" aria-hidden="true"></i></div>';
                    $destContent .= '<div class="col-md-1 removeMappingField" onClick="removeWhField(this,' . $custObjName . ')" style="margin-top:15px;margin-left:25px">
                    <i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div></div>';
                }
            }
            $destContent .= '</div></div></div>';
            $data = '<div class="row"><div class="col-md-12"><textarea rows="4" cols="50" id="Avail' . $key_with_wfId . 'Sides" style="display:none;">' . json_encode($availSideArr) . '</textarea>' . $sourceContent . ' ' . $destContent . '</div></div>';
            return ['data' => $data, 'count' => 1];
        }
    }

    //Mapping other <-> Other load data by source & dest object & append to dynamic created fieldset  //Ex. warehouse to location
    public function mappingWithOther(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $pobjName,
        $data_map_type,
        $slabel,
        $dlabel,
        $mapWith,
        $mapWithCount,
        $iconPath,
        $inputType,
        $sfilterColumn,
        $dfilterColumn
    ) {
        $custObjName = "'" . $mapWith . "'";

        $getOsData = $this->getPlatformObjectId($pobjName);
        $linked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        $new_platform_object_id = ($getOsData->store_with_id) ? $getOsData->store_with_id : $getOsData->id;
        $platform_object_id = $getOsData->linked_with_id;

        $sourceContent = "";
        $destContent = "";
        $dynamicLabelText = "";
        $is_disabled = "";

        if ($mapWithCount < 1) {
            $sourceContent .= '<div class="col-md-12" style="display:flex;padding:0">';
        }

        //load fresh mapping selector
        if ($sRule == "ON") {
            $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $pobjName);
            $dynamicLabelText = ($slabel) ? $slabel : $dynamicLabelText;
            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);

            $sourceContent .= '<div class="col-md-5" style="margin-bottom:30px;">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

            if ($inputType == "number" || $inputType == "text" || $inputType == "email") {
                $sourceContent .= '<input type="' . $inputType . '" value=""  class="form-control form-control-map sourceSelect" placeholder="' . $dynamicLabelText . '"
                    name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . ' />';
            } else if($inputType == "textarea") {
                $sourceContent .= '<textarea rows="3" class="form-control sourceSelect" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . ' ></textarea>';
            } else {
                if ($linked_table == "platform_fields") {
                    $filterData = ($sfilterColumn != "custom_default") ? [$sfilterColumn] : ['custom', 'default'];
                    $sourceData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else if ($linked_table == "es_states") {
                    $sourceData = $this->getStatesData();
                    $data_map_type = "state";
                } else {
                    $sourceData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName);
                    $data_map_type = "object";
                }

                $sourceContent .= '<select class="form-control form-control-map b-1 sourceSelect" name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . '
                    data-data_map_type="' . $data_map_type . '"  data-linked_table="' . $linked_table . '"  ' . $applyValidation . '>
                    <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($sourceData as $value) {
                    $sourceContent .= '<option value="' . $value->optionId . '" >' . strip_tags($value->optionValue) . '</option>';
                }
                $sourceContent .= '</select>';
            }

            $sourceContent .= '</div>';
        }

        if ($dRule == "ON") {
            $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $pobjName);
            $dynamicLabelText = ($dlabel) ? $dlabel : $dynamicLabelText;

            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);

            // $sourceValidation==1 ? $applyValidation="required" : $applyValidation="";
            // $validationLabel = $this->getValidationText($sourceValidation);

            $destData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
            $destContent .= '<div class="col-md-5" style="margin-bottom:30px;">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

            if ($inputType == "number" || $inputType == "text" || $inputType == "email") {
                $destContent .= '<input type="' . $inputType . '" value=""  class="form-control form-control-map destSelect" placeholder="' . $dynamicLabelText . '"
                    name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . '/>';
            } else if($inputType == "textarea") {
                if($mapWith=="inventory_warehouse_TO_ip_s3_access_path") {
                    $is_disabled = "disabled";
                }
                $destContent .= '<textarea rows="3" class="form-control destSelect" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation.' ' . $is_disabled . ' style="display:none"></textarea>';
                $destContent .= '<textarea rows="3" class="form-control destSelect_dammy" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation.' ' . $is_disabled . ' ></textarea>';
            } else {
                if ($linked_table == "platform_fields") {
                    $filterData = ($dfilterColumn != "custom_default") ? [$dfilterColumn] : ['custom', 'default'];
                    $destData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else {
                    $destData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName);
                    $data_map_type = "object";
                }

                $destContent .= '<select class="form-control form-control-map b-1 destSelect" name="' . $pfwfrID . '" data-editId="" alt=' . $new_platform_object_id . '
                    data-data_map_type="' . $data_map_type . '"  data-linked_table="' . $linked_table . '"   ' . $applyValidation . '>
                    <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($destData as $value) {
                    $destContent .= '<option value="' . $value->optionId . '">' . strip_tags($value->optionValue) . '</option>';
                }
                $destContent .= '</select>';
            }

            $destContent .= '</div>';
        }

        if ($mapWithCount == 1) {
            $destContent .= '<div class="col-md-1 addMappingField" style="display:none;margin-top:30px" onClick="addOtherMap(' . $custObjName . ')">
            <i class="fa fa-plus" aria-hidden="true"></i></div>
                <div class="col-md-1 removeMappingField" style="display:none;margin-top:30px" onClick="removeOtherMap(this,' . $custObjName . ')">
                <i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div>';
        }

        //end fresh mapping selector
        $data = $sourceContent . '' . $destContent;
        return ['data' => $data, 'count' => 1];
    }
    //Load store mappings for other <-> other mapping // Ex. warehouse to location
    public function loadDefaultCrossMapStored(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $pobjName,
        $data_map_type,
        $slabel,
        $dlabel,
        $mapWith,
        $mapWithCount,
        $iconPath,
        $mappingDataArr,
        $inputType,
        $sfilterColumn,
        $dfilterColumn,
        $key_with_wfId
    ) {
        $selectVal = "";
        $mapWithObjects = explode("_TO_", $mapWith);

        //get this for add additional filter on destination side also
        $source_pobjName = $mapWithObjects[0];
        $dest_pobjName = $mapWithObjects[1];

        $custObjName = "'" . $mapWith . "'";
        $data = "";
        $i = 0;
        $is_disabled = "";


        foreach ($mappingDataArr as $whData) {

            //manage field input type with saved mappings
            $data_map_type = $whData->data_map_type;
            if ($data_map_type != "object" && $data_map_type != "field") {

                $arryD = explode("_", $data_map_type);

                if ($arryD[0] == "object" || $arryD[0] == "field") {
                    $sourceInputType = "select-one";
                } else if ($arryD[0] == "state" || $arryD[0] == "object") {
                    $sourceInputType = "select-one";
                } else { 
                    $sourceInputType = "text";
                }


                if ($arryD[2] == "object" || $arryD[2] == "field") {
                    $destInputType = "select-one";
                } else if ($arryD[2] == "state" || $arryD[2] == "object") {
                    $destInputType = "select-one";
                } else {
                    $destInputType = "text";
                    if($mapWith=="inventory_warehouse_TO_ip_s3_access_path") {
                        $destInputType = "textarea";
                        $is_disabled = "disabled";
                    }
                }
            } else {
                $sourceInputType = "select-one";
                $destInputType = "select-one";
            }


            $getOsData = $this->getPlatformObjectId($mapWithObjects[0]);
            $linked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;


            $new_platform_object_id = ($getOsData->store_with_id) ? $getOsData->store_with_id : $getOsData->id;
            $platform_object_id = $getOsData->linked_with_id;
            //Start Source Side
            $sourceContent = "";
            $icon = "";
            $endButton = "";
            //$dynamicLabelText = $mapWithObjects[0];
            $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $mapWithObjects[0]);
            $dynamicLabelText = ($slabel) ? $slabel : $dynamicLabelText;

            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);
            $sourceContent .= '<div class="col col-md-5">
            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

            if ($sourceInputType == "number" || $sourceInputType == "text" || $sourceInputType == "email") {
                $sourceContent .= '<input type="' . $sourceInputType . '" value="' . $whData->custom_data . '"  class="form-control form-control-map sourceSelect" placeholder="' . $dynamicLabelText . '" data-data_map_type="custom"
                name="' . $pfwfrID . '" data-editId="' . $whData->id . '"  alt=' . $new_platform_object_id . ' ' . $applyValidation . '/>';
            } else if($sourceInputType == "textarea") {
                $sourceContent .= '<textarea rows="3" class="form-control sourceSelect" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . '  >' . $whData->custom_data . '</textarea>';
            } else {
                if ($linked_table == "platform_fields") {
                    if ($sfilterColumn && $sfilterColumn != "custom_default") {
                        $filterData = ($sfilterColumn != "custom_default") ? [$sfilterColumn] : ['custom', 'default'];
                    } else if ($dfilterColumn && $dfilterColumn != "custom_default") {
                        $filterData = ($dfilterColumn != "custom_default") ? [$dfilterColumn] : ['custom', 'default'];
                    } else {
                        $filterData = ['custom', 'default'];
                    }
                    // $filterData = ($sfilterColumn !="custom_default") ? [$sfilterColumn] : ['custom','default'];
                    $sourceObjectData = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else if ($linked_table == "es_states") {
                    $sourceObjectData = $this->getStatesData();
                    $data_map_type = "state";
                } else {
                    $sourceObjectData = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $platform_object_id, $sourcePltName, $source_pobjName);
                    $data_map_type = "object";
                }

                $sourceContent .= '<select class="form-control form-control-map b-1 sourceSelect" name="' . $pfwfrID . '" data-editId="' . $whData->id . '"
                data-data_map_type="' . $data_map_type . '" alt=' . $new_platform_object_id . '  data-linked_table="' . $linked_table . '"   ' . $applyValidation . '>
                <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($sourceObjectData as $value) {
                    if ($whData->source_row_id == $value->optionId) {
                        $selectVal = "selected";
                    } else {
                        $selectVal = "";
                    }
                    $sourceContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                }
                $sourceContent .= '</select>';
            }

            $sourceContent .= '</div>';

            $icon .= '<div class="col-md-1" style="margin-top:40px"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';

            $getOsData = $this->getPlatformObjectId($mapWithObjects[1]);
            $new_platform_object_id = ($getOsData->store_with_id) ? $getOsData->store_with_id : $getOsData->id;
            $platform_object_id = $getOsData->linked_with_id;


            //Start Dest Side
            $destContent = "";

            $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $mapWithObjects[1]);
            $dynamicLabelText = ($dlabel) ? $dlabel : $dynamicLabelText;

            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);
            // $destValidation==1 ? $applyValidation="required" : $applyValidation="";
            // $validationLabel = $this->getValidationText($destValidation);
            $destContent .= '<div class="col col-md-5">

            <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>';

            if ($destInputType == "number" || $destInputType == "text" || $destInputType == "email") {
                $destContent .= '<input type="' . $destInputType . '" value="' . $whData->custom_data . '"  class="form-control form-control-map destSelect" placeholder="' . $dynamicLabelText . '"  data-data_map_type="custom"
                name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' ' . $applyValidation . '/>';
            } else if($destInputType == "textarea") {

                if($mapWith=="inventory_warehouse_TO_ip_s3_access_path") {
                    $formated_custom_data = $whData->custom_data;
                    $formated_custom_data = str_replace("{","",$formated_custom_data);
                    $formated_custom_data = str_replace("}","",$formated_custom_data);
                    $formated_custom_data = str_replace(",","\n",$formated_custom_data);
                } else {
                    $formated_custom_data = $whData->custom_data;
                }

                $destContent .= '<textarea rows="3" class="form-control destSelect" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . ' '.$is_disabled. ' style="display:none" >' . $whData->custom_data . '</textarea>';
                $destContent .= '<textarea rows="3" class="form-control destSelect_dummy" cols="50"  placeholder="' . $dynamicLabelText . '"
                name="' . $pfwfrID . '" data-editId="' . $whData->id . '" alt=' . $new_platform_object_id . ' data-data_map_type="custom" ' . $applyValidation . ' '.$is_disabled. ' >' . $formated_custom_data . '</textarea>';
            } else {
                if ($linked_table == "platform_fields") {

                    if ($sfilterColumn && $sfilterColumn != "custom_default") {
                        $filterData = ($sfilterColumn != "custom_default") ? [$sfilterColumn] : ['custom', 'default'];
                    } else if ($dfilterColumn && $dfilterColumn != "custom_default") {
                        $filterData = ($dfilterColumn != "custom_default") ? [$dfilterColumn] : ['custom', 'default'];
                    } else {
                        $filterData = ['custom', 'default'];
                    }
                    // $filterData = ($dfilterColumn !="custom_default") ? [$dfilterColumn] : ['custom','default'];
                    $destObjectData = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $pobjName, $filterData);
                    $data_map_type = "field";
                } else {
                    $destObjectData = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $platform_object_id, $destPltName, $dest_pobjName);
                    $data_map_type = "object";
                }

                $destContent .= '<select  class="form-control form-control-map b-1 destSelect" name="' . $pfwfrID . '" data-editId="' . $whData->id . '"
                data-data_map_type="' . $data_map_type . '" alt=' . $new_platform_object_id . ' data-linked_table="' . $linked_table . '"  ' . $applyValidation . '>
                <option value="">Select ' . $dynamicLabelText . '</option>';
                foreach ($destObjectData as $value) {
                    if ($whData->destination_row_id == $value->optionId) {
                        $selectVal = "selected";
                    } else {
                        $selectVal = "";
                    }
                    $destContent .= '<option value="' . $value->optionId . '" ' . $selectVal . '>' . strip_tags($value->optionValue) . '</option>';
                }
                $destContent .= '</select>';
            }
            $destContent .= '</div>';


            if ($i == 0) {
                $i = "";
                $endButton .= '<div class="col-md-1 addMappingField" style="display:none;margin-top:30px" onClick="addOtherMap(' . $custObjName . ')">
                <i class="fa fa-plus" aria-hidden="true"></i></div>
                <div class="col-md-1 removeMappingField" style="display:none;margin-top:30px" onClick="removeOtherMap(this,' . $custObjName . ')">
                <i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div>';
            } else {
                $endButton .= '<div class="col-md-1 addMappingField" style="margin-top:30px;display:none" onClick="addOtherMap(' . $custObjName . ')">
                <i class="fa fa-plus" aria-hidden="true"></i></div>
                <div class="col-md-1 removeMappingField" style="margin-top:30px" onClick="removeOtherMap(this,' . $custObjName . ')">
                <i class="fa fa-times" aria-hidden="true" style="cursor:pointer"></i></div>';
            }


            $data .= '<div class="col-md-12 col-md-12 ' . $mapWith . '_Clone' . $i . '" style="width:100%;display:flex;padding:0">' . $sourceContent . '' . $icon . '' . $destContent . '' . $endButton . '</div>';
            $i++;
        }

        return ['data' => $data, 'count' => 1];
    }


    //load file mapping
    public function fileTypeMapping(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $slabel,
        $dlabel,
        $pobjName,
        $slabelTooltip,
        $dlabelTooltip,
        $contentServerPath,
        $key_with_wfId
    ) {
        $resObjData = $this->getPlatformObjectId($pobjName);
        $platform_object_id = $resObjData->id;
        $fileMappingContent = "";
        $selPlatform = "";
        $dataFileMapping = DB::table('platform_data_mapping')
            ->select('id', 'custom_data')
            ->where('user_integration_id', $userIntegId)
            ->where('platform_workflow_rule_id', $pfwfrID)
            ->where('data_map_type', 'custom')
            ->where('mapping_type', 'default')
            ->where('platform_object_id', $platform_object_id)
            ->where('status', 1)
            ->first();

        if ($dataFileMapping) {
            $editId = $dataFileMapping->id;
            $storeMappingFiles = $dataFileMapping->custom_data;
            if ($storeMappingFiles) {
                $storeMappingFiles = explode(",", $dataFileMapping->custom_data);
            }
        } else {
            $editId = "";
            $storeMappingFiles = "";
        }

        if ($sRule == "ON") {
            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);
            $selPlatform = $sourcePltName;
        } else {
            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);
            $selPlatform = $destPltName;
        }

        $dynamicLabelText = $this->dynamicMappingLabel('ON', 'ON', $pobjName);
        if ($slabel) {
            $dynamicLabelText = $slabel;
        }
        if ($dlabel) {
            $dynamicLabelText = $dlabel;
        }

        //if source rule on....
        if ($sRule) {
            $foundFieldsetLabel = $slabel;
            $foundTooltipText = $slabelTooltip;
        } else {
            $foundFieldsetLabel = $dlabel;
            $foundTooltipText = $dlabelTooltip;
        }
        //set fieldset label
        if ($foundFieldsetLabel) {
            $fieldsetLabel = $foundFieldsetLabel;
        } else {
            $fieldsetLabel = $selPlatform . $dynamicLabelText . ' ' . $validationLabel;
        }
        //set tooltip text
        if ($foundTooltipText) {
            $tooltipText = $foundTooltipText;
        } else {
            $tooltipText = "";
        }

        //set tooltipFor Label
        $labelTooltipHtml = "";
        if ($tooltipText != "" || $tooltipText != NULL) {
            $labelTooltipHtml = '&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="top" style="font-size:18px;cursor: pointer;" data-original-title="' . $tooltipText . '"></i>';
        }


        $fileMappingContent .= '<div class="col-md-12 form-group">
            <label style="display: flex !important;text-align:left !important; font-weight:bold;margin-bottom:10px !important">' . $fieldsetLabel . $labelTooltipHtml . '</label>
            <form action="#" name="uploadForm" enctype="multipart/form-data"
            class="dropzone ' . $key_with_wfId . '" id="' . $key_with_wfId . '" data-wfrId=' . $pfwfrID . ' data-pltObjId="' . $platform_object_id . '"
            data-platform="' . $selPlatform . '" data-editId="' . $editId . '" data-validation="' . $applyValidation . '">
            <div class="dz-message">Drop files here or click to upload.</div>
            <input type="hidden" name="_token" value="' . csrf_token() . '" />
            </form>';

        if ($storeMappingFiles && count($storeMappingFiles) > 0) {
            $fileMappingContent .= '<br>';
            foreach ($storeMappingFiles as $file) {

                //store file upload setting s3bucket/local
                $uploadMappingFilesIn_config = \Config::get('apisettings.uploadMappingFilesIn');
                if ( $uploadMappingFilesIn_config  && $uploadMappingFilesIn_config =="s3bucket") {
                    //use this when bucket used to store files
                    $fileMappingContent .= '<a href="' .$file . '" target="_blank" style="padding:10px"><i class="fa fa-download" aria-hidden="true"></i> ' . basename($file) . '</a>';
                } else {
                     //enable this when use local server to store files
                    $fileMappingContent .= '<a href="' . $contentServerPath . '/' . $file . '" target="_blank" style="padding:10px"><i class="fa fa-download" aria-hidden="true"></i> ' . basename($contentServerPath . '/' . $file) . '</a>';
                }
 
                
            }
        }

        $fileMappingContent .= '<textarea rows="4" cols="50" class="storeMappingFiles_' . $key_with_wfId . '" style="display:none;">' . json_encode($storeMappingFiles) . '</textarea>
          </div>';
        return $fileMappingContent;
    }
    public function loadMappingWithMultiFields(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $slabel,
        $dlabel,
        $pobjName,
        $iconPath,
        $key_with_wfId
    ) {
        $resObjData = $this->getPlatformObjectId($pobjName);

        $platform_object_id = ($resObjData->linked_with_id) ? $resObjData->linked_with_id : $resObjData->id;
        if ($resObjData->store_with_id) {
            $new_platform_object_id = $resObjData->store_with_id;
        } else {
            $new_platform_object_id = $resObjData->id;
        }

        $Content = "";

        if ($sRule == "ON") {
            $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($sourceValidation);
            $selPlatform = $sourcePltName;
            $pltId = $sourcePlt;
        } else {
            $destValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
            $validationLabel = $this->getValidationText($destValidation);
            $selPlatform = $destPltName;
            $pltId = $destPlt;
        }

        $dataSwitchMapQry = DB::table('platform_data_mapping')
            ->where('user_integration_id', $userIntegId)
            ->where('platform_workflow_rule_id', $pfwfrID)
            ->where('mapping_type', 'default');
        if ($pobjName == "full_inventory_sync") {
            $dataSwitchMapQry->where('data_map_type', 'custom')->where('platform_object_id', $platform_object_id);
        }
        $dataSwitchMap = $dataSwitchMapQry->select('id', 'custom_data', 'status')->first();

        if ($pobjName == "full_inventory_sync") {
            $inv_syn = "";
            $syn_time = "";
            $editId = "";
            $selected = "";
            if ($dataSwitchMap) {
                $defaultVal = $dataSwitchMap->custom_data;
                if ($defaultVal) {
                    $customDataArr = explode('|', $defaultVal);
                    if (count($customDataArr) > 0) {
                        $inv_syn = $customDataArr[0];
                        $syn_time = $customDataArr[1];
                    }
                }
                $editId = $dataSwitchMap->id;
            }

            $selected = ($inv_syn == "Twice") ? "selected" : "";
            $Content .= '<div class="col-md-row ' . $key_with_wfId . '" style="display:flex;">
                <div class="col-md-6 form-group mappingFieldSec" >
                    <label for="full_inv_syn"  style="font-weight:bold;">' . $selPlatform . ' Inventory Frequency ' . $validationLabel . '</label>
                    <select class="form-control form-control-map full_inv_syn" data-wfrId=' . $pfwfrID . ' data-pltObjId=' . $platform_object_id . ' data-plt=' . $selPlatform . '
                    data-validation="' . $applyValidation . '" data-editId="' . $editId . '">
                    <option value="Once">Every 24 hours</option>
                    <option value="Twice" ' . $selected . '>Every 12 hours</option>
                </select>
                </div>
                <div class="col-md-6 form-group mappingFieldSec">

                <label for="full_inv_syn_dt" style="font-weight:bold;">Run Time in UTC (Universal Time Coordinated)' . $validationLabel . '  <i class="fa fa-question-circle;cursor: pointer" aria-hidden="true" style="font-size:18px" data-bs-toggle="tooltip" data-bs-placement="top" title="Set Run Time in GMT"></i></label>
                <input type="text" value="' . $syn_time . '" id="pickatime" class="form-control form-control-map pickatime full_inv_syn_dt" placeholder="HH:MM" />
            </div></div>';
            return $Content;
        }
    }
    //end main condition


    //regular mapping without + - button
    public function loadOpenedRegularMapping(
        $pfwfrID,
        $sourcePlt,
        $destPlt,
        $event_name,
        $i,
        $sourceEvent,
        $destEvent,
        $userId,
        $userIntegId,
        $sourcePltName,
        $destPltName,
        $sRule,
        $dRule,
        $sourceValidation,
        $destValidation,
        $pobjName,
        $iconPath,
        $slabel,
        $dlabel
    ) {

        $selectVal = "";
        //If mapping object content source & destination side both ex.. sales_order_to_purchase_order, purchase_order_to_sales_order
        if (str_contains($pobjName, '_TO_')) {
            
            $mapping_type = "cross";
            //get source & destination Object Name
            $prodname_arr = explode('_TO_', $pobjName);
            if (isset($prodname_arr[0]) && isset($prodname_arr[1])) {

                $source_pobjName = $prodname_arr[0];
                $dest_pobjName = $prodname_arr[1];

                $pltObjDataSource = $this->getPlatformObjectId($source_pobjName);
                $platform_object_id_source = $pltObjDataSource->id;

                $pltObjDataDest = $this->getPlatformObjectId($dest_pobjName);
                $platform_object_id_dest = $pltObjDataDest->id;

                //store with source
                $store_with_obj_id = $platform_object_id_source;

            }

        } else {
            $mapping_type = "regular";
            $pltObjData = DB::table('platform_objects')->select('id')->where('name', $pobjName)->first();
            $platform_object_id_source = $pltObjData->id;
            $platform_object_id_dest = $pltObjData->id;
            $store_with_obj_id = $pltObjData->id;
            $source_pobjName = $pobjName;
            $dest_pobjName = $pobjName;
        }

        $sourceValidation == 1 ? $applyValidation = "required" : $applyValidation = "";
        $validationLabel = $this->getValidationText($sourceValidation);

        //get source object linked data
        $getOsData = $this->getPlatformObjectId($source_pobjName);
        $s_linked_platform_object_id = $getOsData->linked_with_id;
        $slinked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        //one to one mappuing
        if ($slinked_table == "platform_fields") {
            $data_map_type = 'field';
            $FMdata = $this->getPlatformFieldData($sourcePlt, $userIntegId, $userId, $s_linked_platform_object_id, $sourcePltName, $source_pobjName);
        } else {
            $data_map_type = 'object';
            $FMdata = $this->getPlatformObjectData($sourcePlt, $userIntegId, $userId, $s_linked_platform_object_id, $sourcePltName, $source_pobjName);
        }


        $fmContent = "";
        $fieldsMappingArr = [];
        $fmContent .= '<div class="row">';

        //get destination object linked data
        $getOsData = $this->getPlatformObjectId($dest_pobjName);
        $d_linked_platform_object_id = $getOsData->linked_with_id;
        $dlinked_table = ($getOsData->linked_table) ? $getOsData->linked_table : null;

        for ($m = 0; $m < count($FMdata); $m++) {

            //get destination side data
            if ($dlinked_table == "platform_fields") {
                $data_map_type = 'field';
                $optionValues = $this->getPlatformFieldData($destPlt, $userIntegId, $userId, $d_linked_platform_object_id, $destPltName, $dest_pobjName);
            } else {
                $data_map_type = 'object';
                $optionValues = $this->getPlatformObjectData($destPlt, $userIntegId, $userId, $d_linked_platform_object_id, $destPltName, $dest_pobjName);
            }


            $FMdata[$m]->optionFields = $optionValues;
        }


        $j = 0;
        $i++;
        $fmContent .= '<input type="hidden" value="' . count($FMdata) . '" id="totalFieldInSec' . $i . '" name=' . $pfwfrID . '>
                        <input type="hidden" value="' . $destEvent . '" id="FieldMappingDestEvent">';

        array_push($fieldsMappingArr, 'source', 'destination');
        foreach ($FMdata as $value) {
            $editId = "";
            $selectedMF = DB::table('platform_data_mapping')
                ->where('data_map_type', $data_map_type)
                ->where('platform_object_id', $store_with_obj_id)
                ->where('platform_workflow_rule_id', $pfwfrID)
                ->where('user_integration_id', $userIntegId)
                ->where('source_row_id', $value->optionId)
                ->where('status',1)
                ->first();
            if ($selectedMF) {
                $editId = $selectedMF->id;
            }

            $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $source_pobjName);
            if ($slabel) {
                $dynamicLabelText = $slabel;
            }

            $j++;
            $fmContent .= '<div class="col-md-12 Section' . $i . '" style="display:flex">
            <textarea rows="4" cols="50" id="AvailFieldSides" style="display:none">' . json_encode($fieldsMappingArr) . '</textarea>

                <div class="col-md-5">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $sourcePltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>
                    <select class="form-control sourceField' . $j . '"  alt=' . $store_with_obj_id . ' data_map_type=' . $data_map_type . ' mapping_type=' . $mapping_type . ' data-editId="' . $editId . '" >
                    <option value="' . $value->optionId . '" selected>'.$value->optionValue.'</option>
                    </select>
                </div>';

                $fmContent .= '<div class="col-md-1" style="margin-top:40px"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';

                $dynamicLabelText = $this->dynamicMappingLabel($sRule, $dRule, $dest_pobjName);
                if ($dlabel) {
                    $dynamicLabelText = $dlabel;
                }

                $fmContent.='<div class="col-md-5">
                <label style="display: flex !important;text-align:left !important; font-weight:bold">' . $destPltName . ' ' . $dynamicLabelText . ' ' . $validationLabel . '</label>
                    <select class="form-control form-control-map b-1 destField' . $j . '" alt=' . $store_with_obj_id . ' ' . $applyValidation . '>
                    <option value="">Select '.$dynamicLabelText.'</option>';
                    foreach ($value->optionFields as $v) {
                        $selectVal = "";
                        if ($selectedMF) {
                            if ($selectedMF->destination_row_id == $v->optionId) {
                                $selectVal = "selected";
                            }
                        } else {
                            $selectVal = "";
                        }
                        $fmContent .= '<option value="' . $v->optionId . '" ' . $selectVal . '>' . $v->optionValue . '</option>';
                    }
                    
                $fmContent .= '</select></div></div>';
                
        }

        $fmContent .= '</div>';
        $fmContent .= '<br>';

        return ['data' => $fmContent, 'count' => 1];
    }


    
}
