<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoginLog;

class LoginLogController extends Controller
{
    function getIpAddr(){
        if (!empty($_SERVER['HTTP_CLIENT_IP'])){
           $ipAddr=$_SERVER['HTTP_CLIENT_IP'];
        }elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
           $ipAddr=$_SERVER['HTTP_X_FORWARDED_FOR'];
        }else{
           $ipAddr=$_SERVER['REMOTE_ADDR'];
        }
       return $ipAddr;
    }

    public function loginAttempts($email,$ipAdress){
        $lockTime=time()-60;
        $totalAttempts = LoginLog::where(['email'=>$email, 'ip'=>$ipAdress])->where('time','>',$lockTime)->count();
        
        if($totalAttempts >= 3){
            $msg="To many failed login attempts. Please login after 60 sec";
            return $msg;
        }
        $this->increaseAttempts($email,$ipAdress);
        return '';
        
    }

    public function checkLoginLock($email,$ipAdress){
        $lockTime=time()-60;
        $totalAttempts = LoginLog::where(['email'=>$email, 'ip'=>$ipAdress])->where('time','>',$lockTime)->count();
        if($totalAttempts >= 3){
            return 1;
        }else{
            $this->deleteLoginAttepts($email,$ipAdress);
            return 0;
        }
    }
    
    public function increaseAttempts($email,$ipAdress){
            $loginLog = new LoginLog();
            $loginLog->email = $email;
            $loginLog->ip = $ipAdress;
            $loginLog->time = time();
            $loginLog->save();
    }

    public function deleteLoginAttepts($email,$ipAdress){
        LoginLog::where(['email'=>$email, 'ip'=>$ipAdress])->delete();
    }
}