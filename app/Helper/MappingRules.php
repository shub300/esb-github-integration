<?php

namespace App\Helper;

use App\Helper\MainModel;
use DB;

class MappingRules
{
    public function getRuleStatusByIntegration($Rules, $pfwfrID, $featuresName)
    {
        $statusCode = 1;
        $sourceFeature = "OFF";
        $destFeature = "OFF";
        $sourceValidation = 1;
        $destValidation = 1;
        $source_input_type = "select_list";
        $dest_input_type = "select_list";
        $slabel = null;
        $dlabel = null;
        $pltWFR = null;
        $map_with = "";
        $sfieldsetLabel = null;
        $dfieldsetLabel = null;
        $slinkedTable = null;
        $dlinkedTable = null;
        $sfilterColumn = null;
        $dfilterColumn = null;
        $stooltipText = null;
        $dtooltipText = null;
        $slabelTooltip = null;
        $dlabelTooltip = null;
        $sfieldsetName = null;
        $dfieldsetName = null;
        $sfieldsetNameTooltip = null;
        $dfieldsetNameTooltip = null;
        $sloadAllStatus = null;
        $dloadAllStatus = null;
        $suniqueMappingCheck = null;
        $duniqueMappingCheck = null;

        //check wfId in rule & get required data
        if (array_key_exists($pfwfrID, $Rules)) {
            if (array_key_exists('source', $Rules[$pfwfrID])) {
                $sourceRules = $Rules[$pfwfrID]['source'];
                foreach ($sourceRules as $sRule) {
                    if (array_key_exists($featuresName, $sRule)) {
                        $sRule[$featuresName] == 1 ? $sourceFeature = "ON" : $sourceFeature = "OFF";
                        if ($sourceFeature == "ON") {
                            //if required exists in rule
                            if (array_key_exists('required', $sRule)) {
                                $sRule['required'] == 0 ? $sourceValidation = false : $sourceValidation = true;
                            }
                            //if input type exists in rule
                            if (array_key_exists('input_type', $sRule)) {
                                $source_input_type = $sRule['input_type'];
                            }
                            //if label exists in rule
                            if (array_key_exists('label', $sRule)) {
                                $slabel = $sRule['label'] != "" ? $sRule['label'] : $slabel;
                            }
                            $pltWFR = $pfwfrID;
                            //end
                            //if map_with exists in rule
                            if (array_key_exists('map_with', $sRule)) {
                                $map_with = $sRule['map_with'] != "" ? $sRule['map_with'] : $map_with;
                            }
                            //if fielsetlabel exists in rule
                            if (array_key_exists('fieldsetLabel', $sRule)) {
                                $sfieldsetLabel = $sRule['fieldsetLabel'] != "" ? $sRule['fieldsetLabel'] : $sfieldsetLabel;
                            }
                            //if tooltipText exists in rule
                            if (array_key_exists('tooltipText', $sRule)) {
                                $stooltipText = $sRule['tooltipText'] != "" ? $sRule['tooltipText'] : $stooltipText;
                            }
                            //if labelTooltip exists in rule
                            if (array_key_exists('labelTooltip', $sRule)) {
                                $slabelTooltip = $sRule['labelTooltip'] != "" ? $sRule['labelTooltip'] : $slabelTooltip;
                            }
                            //if filterColumn exists in rule
                            if (array_key_exists('filterColumn', $sRule)) {
                                $sfilterColumn = $sRule['filterColumn'] != "" ? $sRule['filterColumn'] : $sfilterColumn;
                            }
                            //if linkedTabel exists in rule
                            if (array_key_exists('linkedTable', $sRule)) {
                                $slinkedTable = $sRule['linkedTable'] != "" ? $sRule['linkedTable'] : $slinkedTable;
                            }

                            //if fieldsetName exists in rule
                            if (array_key_exists('fieldsetName', $sRule)) {
                                $sfieldsetName = $sRule['fieldsetName'] != "" ? $sRule['fieldsetName'] : $sfieldsetName;
                            }

                            //if fieldsetName exists in rule
                            if (array_key_exists('fieldsetNameTooltip', $sRule)) {
                                $sfieldsetNameTooltip = $sRule['fieldsetNameTooltip'] != "" ? $sRule['fieldsetNameTooltip'] : $sfieldsetNameTooltip;
                            }

                            //if loadAllStatus exists in rule
                            if (array_key_exists('loadAllStatus', $sRule)) {
                                $sloadAllStatus = $sRule['loadAllStatus'] != "" ? $sRule['loadAllStatus'] : $sloadAllStatus;
                            }

                            //if loadAllStatus exists in rule
                            if (array_key_exists('uniqueMappingCheck', $sRule)) {
                                $suniqueMappingCheck = $sRule['uniqueMappingCheck'] != "" ? $featuresName.'-'.$sRule['uniqueMappingCheck'] : $suniqueMappingCheck;
                            }

                        }
                    }
                }
            }
            //if source rule not added in rules 
            else {
                $statusCode = 0;
            }

            if (array_key_exists('destination', $Rules[$pfwfrID])) {
                $destRules = $Rules[$pfwfrID]['destination'];
                foreach ($destRules as $dRule) {
                    if (array_key_exists($featuresName, $dRule)) {
                        $dRule[$featuresName] == 1 ? $destFeature = "ON" : $destFeature = "OFF";
                        if ($destFeature == "ON") {
                            //if rules hase required
                            if (array_key_exists('required', $dRule)) {
                                $destValidation = ($dRule['required'] == 0) ? 0 : 1;
                            }
                            //if input type exists in rule
                            if (array_key_exists('input_type', $dRule)) {
                                $dest_input_type = $dRule['input_type'];
                            }
                            //if label exists in rule
                            if (array_key_exists('label', $dRule)) {
                                $dlabel = $dRule['label'] != "" ? $dRule['label'] : $dlabel;
                            }
                            $pltWFR = $pfwfrID;
                            //end 
                             //if map_with exists in rule
                            if (array_key_exists('map_with', $dRule)) {
                                $map_with = $dRule['map_with'] != "" ? $dRule['map_with'] : $map_with;
                            }
                            //if fielsetlabel exists in rule
                            if (array_key_exists('fieldsetLabel', $dRule)) {
                                $dfieldsetLabel = $dRule['fieldsetLabel'] != "" ? $dRule['fieldsetLabel'] : $dfieldsetLabel;
                            }
                            //if tooltipText exists in rule
                            if (array_key_exists('tooltipText', $dRule)) {
                                $dtooltipText = $dRule['tooltipText'] != "" ? $dRule['tooltipText'] : $dtooltipText;
                            }
                            //if labelTooltip exists in rule
                            if (array_key_exists('labelTooltip', $dRule)) {
                                $dlabelTooltip = $dRule['labelTooltip'] != "" ? $dRule['labelTooltip'] : $dlabelTooltip;
                            }
                            //if filterColumn exists in rule
                            if (array_key_exists('filterColumn', $dRule)) {
                                $dfilterColumn = $dRule['filterColumn'] != "" ? $dRule['filterColumn'] : $dfilterColumn;
                            }
                            //if linkedTabel exists in rule
                            if (array_key_exists('linkedTable', $dRule)) {
                                $dlinkedTable = $dRule['linkedTable'] != "" ? $dRule['linkedTable'] : $dlinkedTable;
                            }
                            //if fieldsetName exists in rule
                            if (array_key_exists('fieldsetName', $dRule)) {
                                $dfieldsetName = $dRule['fieldsetName'] != "" ? $dRule['fieldsetName'] : $dfieldsetName;
                            }

                            //if fieldsetName exists in rule
                            if (array_key_exists('fieldsetNameTooltip', $dRule)) {
                                $dfieldsetNameTooltip = $dRule['fieldsetNameTooltip'] != "" ? $dRule['fieldsetNameTooltip'] : $dfieldsetNameTooltip;
                            }

                            //if loadAllStatus exists in rule
                            if (array_key_exists('loadAllStatus', $dRule)) {
                                $dloadAllStatus = $dRule['loadAllStatus'] != "" ? $dRule['loadAllStatus'] : $dloadAllStatus;
                            }
                            
                            //compare mapping object with for unique check
                            if (array_key_exists('uniqueMappingCheck', $dRule)) {
                                $duniqueMappingCheck = $dRule['uniqueMappingCheck'] != "" ? $featuresName.'-'.$dRule['uniqueMappingCheck'] : $duniqueMappingCheck;
                            }

                            
                            

                        }
                    }
                }
            }
            //if destination rules not added in rules
            else {
                $statusCode = 0;
            }
        }

        //if platform workflow rule Id not added in rules
        // foreach ($pfwfrIDs as $pfwfrID) {
            //Arry key exists check removed from loop & set wfrId check
        // }   
        

        $statusText = ($statusCode == 0) ? "There is some thing went wrong! Please contact your administrator for mapping" : "success";

        return ([
            'status_code' => $statusCode, 'status_text' => $statusText, 'sRule' => $sourceFeature, 'dRule' => $destFeature,
            'source_input_type' => $source_input_type, 'dest_input_type' => $dest_input_type,
            'sValidation' => $sourceValidation, 'dValidation' => $destValidation, 'slabel' => $slabel, 'dlabel' => $dlabel,
            'pltWFR'=> $pltWFR, 'map_with' => $map_with, 'slinkedTable' => $slinkedTable, 'dlinkedTable' => $dlinkedTable,
            'sfieldsetLabel' => $sfieldsetLabel, 'dfieldsetLabel' => $dfieldsetLabel, 'sfilterColumn' => $sfilterColumn,
            'dfilterColumn' => $dfilterColumn, 'stooltipText' => $stooltipText, 'dtooltipText' => $dtooltipText,'slabelTooltip' => $slabelTooltip, 'dlabelTooltip' => $dlabelTooltip, 
            'sfieldsetName' => $sfieldsetName, 'dfieldsetName' => $dfieldsetName, 'sfieldsetNameTooltip' => $sfieldsetNameTooltip, 'dfieldsetNameTooltip' => $dfieldsetNameTooltip, 'sloadAllStatus' => $sloadAllStatus, 'dloadAllStatus' => $dloadAllStatus, 'suniqueMappingCheck' => $suniqueMappingCheck, 'duniqueMappingCheck' => $duniqueMappingCheck
        ]);

    }
}
