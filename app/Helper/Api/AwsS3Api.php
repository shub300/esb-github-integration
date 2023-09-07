<?php

namespace App\Helper\Api;

use DB;
use Auth;
use Mail;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Illuminate\Database\Eloquent\Model;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class AwsS3Api extends Model
{

    /**
     * Create a new model instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper();
        $this->my_platform = 'spscommerce';
        $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
    }

    public function CheckAWSCredentials($account=[])
    {
        $return = "";

        try {

            $credentials = new \Aws\Credentials\Credentials($account['aws_access_key'], $account['aws_secret_key']);

            $s3 = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => $account['aws_region'],
                'credentials' => $credentials
            ]);
            $result = $s3->listBuckets();
            /*
             // Convert the result object to a PHP array
             $array = $result->toArray();
             echo"<pre>";
             print_r($array);
            */
            if(isset($result['Buckets'])){
                $return = "Success";
            }else{
                $return = "Invalid AWS-S3 Credentials.\n";
            }

        } catch (\Aws\S3\Exception\S3Exception $e) {
            $return = "Invalid AWS-S3 Credentials.\n";
        }
        return $return;
    }

    public function AWSUploadFile($account,$additional_info=[])
    {

        try {

            $client = new S3Client([
                'region' => $account->region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->mobj->encrypt_decrypt($account->access_key,'decrypt'),
                    'secret' => $this->mobj->encrypt_decrypt($account->secret_key,'decrypt'),
                ],
            ]);

            $result = $client->putObject(array(
                'Bucket'     => $additional_info['bucket'],
                'Key'        => $additional_info['access_folder'].'/'.$additional_info['file_name'],
                'ContentLength' => filesize($additional_info['path_to_file']),
                'Body'   => fopen($additional_info['path_to_file'], 'r' )
            ));
            echo"<pre>";
            print_r($result);
            return "Success";
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return "There was an error uploading the file.\n";
        }
    }



    public function AWSGetListObject($account,$additional_info=[])
    {

        try {



            $client = new S3Client([
                'region' => $account->region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->mobj->encrypt_decrypt($account->access_key,'decrypt'),
                    'secret' => $this->mobj->encrypt_decrypt($account->secret_key,'decrypt'),
                ],
            ]);

            /*$result = $client->getObject(array(
                'Bucket' => $additional_info['bucket'],
                'Key'    => $additional_info['access_folder']
            ));*/



            $result = $client->getIterator('ListObjects', array(
                'Bucket' => $additional_info['bucket'],
                'Prefix' => $additional_info['access_folder']
            ));

            //echo"<pre>";
            //print_r($result);

            return $result;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return "There was an error getting files.\n";
        }
    }

    public function AWSGetObject($account,$additional_info=[])
    {

        try {

            $client = new S3Client([
                'region' => $account->region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->mobj->encrypt_decrypt($account->access_key,'decrypt'),
                    'secret' => $this->mobj->encrypt_decrypt($account->secret_key,'decrypt'),
                ],
            ]);


            $result = $client->getObject(array(
                'Bucket' => $additional_info['bucket'],
                'Key'    => $additional_info['object_key'],  //folder with file name which we want to access
                'SaveAs' => $additional_info['server_access_folder'].$additional_info['file_name']   //save file to portal server
            ));
            echo"<pre>";
            print_r($result);
            return $result;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return "There was an error getting files.\n";
        }
    }


    public function AWSCopyObject($account,$additional_info=[])
    {

        try {

            $client = new S3Client([
                'region' => $account->region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->mobj->encrypt_decrypt($account->access_key,'decrypt'),
                    'secret' => $this->mobj->encrypt_decrypt($account->secret_key,'decrypt'),
                ],
            ]);


            $sourceBucket = $additional_info['bucket'];
            $sourceKeyname = $additional_info['object_key'];
            $targetBucket = $additional_info['bucket'];
            $result=$client->copyObject([
                'Bucket'     => $targetBucket,
                'Key'        => $additional_info['archived_access_folder'].'/'.$additional_info['file_name'],
                'CopySource' => "{$sourceBucket}/{$sourceKeyname}",
            ]);


            echo"<pre>";
            print_r($result);
            return $result;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return "There was an error copying files.\n";
        }
    }


    public function AWSDeleteObject($account,$additional_info=[])
    {

        try {

            $client = new S3Client([
                'region' => $account->region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->mobj->encrypt_decrypt($account->access_key,'decrypt'),
                    'secret' => $this->mobj->encrypt_decrypt($account->secret_key,'decrypt'),
                ],
            ]);


            $result = $client->deleteObject([
                'Bucket' => $additional_info['bucket'],
                'Key'    => $additional_info['object_key']
            ]);


            echo"<pre>";
            print_r($result);
            return $result;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return "There was an error getting files.\n";
        }
    }





}
