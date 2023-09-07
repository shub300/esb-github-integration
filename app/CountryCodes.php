<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CountryCodes extends Model
{
    protected $table = 'es_country_codes';

    public static function getCountryNameFromIso($iso) : string {
        $codeObj = self::where('iso','=',$iso)->first();
        if($codeObj) {
            return $codeObj->name;
        }
        return '';
    }

    public static function getCountryIsoFromName($name) : string {
        $codeObj = self::where('name','=',$name)->first();
        if($codeObj) {
            return $codeObj->iso;
        }
        return '';
    }

    public static function getNetSuiteSpecificCountryNameByIso($iso) : string {
        $nsCountry = '';
        $name = self::getCountryNameFromIso($iso);
        if($name != '') {
            $nameArr = explode(' ', $name);
            array_map('ucfirst',$nameArr);
            $nsCountry = '_';
            $nameStr = lcfirst(implode('', $nameArr));
            $nsCountry .= $nameStr;
        }
        return $nsCountry;
    }
    

    
}
