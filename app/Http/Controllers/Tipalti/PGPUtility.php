<?php

namespace App\Http\Controllers\Tipalti;

use DB;
use Auth;
use Mail;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;

class PGPUtility extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function CheckPGPCredentials($data)
    {

        // checking Public Key
        $res = gnupg_init();
        $rtv_public = gnupg_import($res, $data['public_key']);
        if(!isset($rtv_public['fingerprint'])){
            return "Invalid Public Key";
        }

        // checking Public Key
        $res = gnupg_init();
        $rtv_private = gnupg_import($res, $data['private_key']);
        if(!isset($rtv_private['fingerprint'])){
            return "Invalid Private Key";
        }

        /*if($rtv_public['fingerprint']!=$rtv_private['fingerprint']){
            return "Invalid Public & Private Keys";
        }*/

        return "Success";

    }


    public function EncryptDataPGP($account_detail,$additional_info=[],$data_to_encrypt=null)
    {


        $encyption_status = "Success";
        // create new GnuPG object
        $gpg = new \gnupg();
        try {
            // throw exception if error occurs
            $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);
            $info = $gpg->import($account_detail->access_token);  // $account_detail->access_token  is having public key
            $gpg->addencryptkey($info['fingerprint']);
            $ciphertext = $gpg->encrypt($data_to_encrypt);
            //echo '<pre>' . $ciphertext . '</pre>';
            //die;
            file_put_contents($additional_info['encrypted_file_name'], $ciphertext);
        } catch (Exception $e) {
            $encyption_status = $e->getMessage();
        }
        return $encyption_status;
    }


    public function DecryptDataPGP($account_detail,$additional_info=[])
    {


        $decyption_status = "Success";
        try {
            shell_exec('gpg --batch --passphrase '.$this->mobj->encrypt_decrypt($account_detail->app_secret,'decrypt').' -d '.$additional_info['file_to_decrypt'].' > '.$additional_info['decrypted_file_name']);
        } catch (Exception $e) {
            $decyption_status = $e->getMessage();
        }
        return $decyption_status;
    }



}



