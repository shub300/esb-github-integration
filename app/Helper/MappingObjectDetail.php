<?php

namespace App\Helper;
use App\Helper\MainModel;
use DB;

class MappingObjectDetail
{
    public function getCustomLabelFromMappingRule($RuleArray,$pltIntegId)
    {   
        $formated_mapping_rules = [];
        if($RuleArray) {
            foreach($RuleArray as $pwfrId => $Rules ) {

                if (array_key_exists('source', $Rules)) {
                    $sourceRules = $Rules['source'];
                    foreach($sourceRules as $rule_line) {

                        //get first key as object name
                        $objectName = array_key_first($rule_line);
                        //check label
                        $object_label = isset($rule_line['label']) ?  $rule_line['label'] : NULL;

                        if($object_label) {
                            $formated_mapping_rules[$objectName] = $object_label;
                        }

                    }
                }

                if (array_key_exists('destination', $Rules)) {
                    $destRules = $Rules['destination'];
                    foreach($destRules as $rule_line) {

                        //get first key as object name
                        $objectName = array_key_first($rule_line);
                        //check label
                        $object_label = isset($rule_line['label']) ?  $rule_line['label'] : NULL;

                        if($object_label) {
                            $formated_mapping_rules[$objectName] = $object_label;
                        }

                    }
                }

            }
        }

       return $formated_mapping_rules;
    
    }


}
