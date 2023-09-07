<?php

namespace App\Helper;
use App\Helper\MainModel;
class IntegrationCommonHelper extends FieldMappingHelper
{
    public function findDefaultLineOfBusiness($userIntegrationId=null,$platformWorkFlowId=null){
          /* Default LOB */
      $default_lob =  $this->getMappedDataByName($userIntegrationId, $platformWorkFlowId, "default_order_line_of_business",  ['api_id'], "default");

      if ($default_lob) {

          $default_order_lob = $default_lob->api_id;
      } else {
          $default_order_lob = 0;
      }
      return $default_order_lob;
    }    
}
    

