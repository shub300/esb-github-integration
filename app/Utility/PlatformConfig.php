<?php

namespace App\Utility;

class PlatformConfig
{

    public function accessControl($sourcePlatformName, $destinationPlatformName){

        $status = false;
        $action = null;
        
        if(isset(\Config::get('accesscontrolsetting.shareData')[$sourcePlatformName.'&'.$destinationPlatformName])){
            $status = true;
            $action = 'share';
        }elseif(isset(\Config::get('accesscontrolsetting.shareData')[$destinationPlatformName.'&'.$sourcePlatformName])){
            $status = true;
            $action = 'share';
        }
        
        if(!$status){
            if(isset(\Config::get('accesscontrolsetting.copyData')[$sourcePlatformName.'&'.$destinationPlatformName])){
                $status = true;
                $action = 'copy';
            }elseif(isset(\Config::get('accesscontrolsetting.copyData')[$destinationPlatformName.'&'.$sourcePlatformName])){
                $status = true;
                $action = 'copy';
            }
        }

        return ['status'=>$status, 'action'=>$action];
    }

    public function platformCombination($sourcePlatformName, $destinationPlatformName){
        $combination = null;
        if(isset(\Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$sourcePlatformName.'&'.$destinationPlatformName])){
            $combination = $sourcePlatformName.'&'.$destinationPlatformName;
        }elseif(isset(\Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$destinationPlatformName.'&'.$sourcePlatformName])){
            $combination = $destinationPlatformName.'&'.$sourcePlatformName;
        }
        return $combination;
    }
}
    

