<?php

namespace App\Helper;

use App\Helper\Cache\CacheDecoder;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformLookup;
use App\Models\PlatformObject;
use App\Models\PlatformObjectData;
use App\Models\Platform;
use DB;

class ConnectionHelper
{

    /**
     * Provides suggested workflow
     *
     * @return Collection
     */
    public $mobj;
    public $cache;
    public function __construct()
    {
        $this->mobj = new MainModel;
        $this->cache = new CacheDecoder;
    }
    /* Get Number Format */
    public function getNumberFormat($amount, $digit = 4)
    {
        return (string) number_format($amount, $digit, '.', '');
    }
    public function getIntegrationById($id, $user_id)
    {


        $workflowd = DB::table('user_integrations as uw')
            ->join('platform_integrations', 'platform_integrations.id', '=', 'uw.platform_integration_id')
            ->join('platform_lookup as p1', 'platform_integrations.source_platform_id', '=', 'p1.id')
            ->join('platform_lookup as p2', 'platform_integrations.destination_platform_id', '=', 'p2.id')
            ->where([
                'platform_integrations.status' => 1, 'p1.status' => 1, 'p2.status' => 1, 'uw.id' => $id,
                'uw.user_id' => $user_id
            ])
            ->select(
                'platform_integrations.id as integration_id',
                'p1.id as p1_rowid',
                'p1.platform_id as p1_id',
                'p1.platform_name as p1_name',
                'p1.platform_image as p1_image',
                'p2.id as p2_rowid',
                'p2.platform_id as p2_id',
                'p2.platform_name as p2_name',
                'p2.platform_image as p2_image',
                'p1.auth_endpoint as p1_auth_endpoint',
                'p2.auth_endpoint as p2_auth_endpoint',
                'p1.auth_type as p1_auth_type',
                'p2.auth_type as p2_auth_type',
                'source_platform_id',
                'destination_platform_id',
                'uw.selected_sc_account_id as source_account_id',
                'uw.selected_dc_account_id as destination_account_id',
                'uw.workflow_status as workflow_status',
                'uw.flow_name as flow_name'
            )->first();


        return $workflowd;
    }

    /**
     * Provides platform events
     * @param $platform_id
     * @return Array of platform options & data
     */
    public function getPlatformAccounts($platform_id = '')
    {

        $platform = DB::table('platform_accounts')->join('platform_lookup', 'platform_lookup.platform_id', '=', 'platform_accounts.platform_id')
            ->where(['platform_lookup.status' => 1, 'platform_accounts.status' => 1, 'platform_accounts.platform_id' => $platform_id])
            ->select(['platform_accounts.platform_id', 'platform_lookup.auth_endpoint', 'platform_accounts.id', 'platform_accounts.account_name', 'platform_lookup.platform_image'])->get();

        $options = '';
        $opt_data = [];
        $auth_endpoint = '';
        foreach ($platform as $pv) {
            $platform_image = asset($pv->platform_image);

            $options .= '<option data-icon="' . $platform_image . '" value="' . $pv->id . '">' . $pv->account_name . '</option>';
            $opt_data[] = $pv;
        }

        $platform_url = DB::table('platform_lookup')
            ->where(['platform_lookup.status' => 1, 'platform_lookup.platform_id' => $platform_id])
            ->select(['platform_lookup.auth_endpoint', 'platform_lookup.platform_image'])->first();
        if (trim($platform_url->auth_endpoint))
            $auth_endpoint = url($platform_url->auth_endpoint);

        return ['options' => $options, 'data' => $opt_data, 'auth_endpoint' => $auth_endpoint];
    }

    /**
     * Provides platform by organization
     * @param $platform_id, $org
     * @return Array of platform options & data
     */
    public function getAccountPlatformByOrg($platform_id = NULL, $org = NULL)
    {
        $platform = DB::table('platform_accounts')->join('platform_lookup', 'platform_lookup.id', '=', 'platform_accounts.platform_id')
            ->where([
                'platform_lookup.status' => 1, 'platform_accounts.user_id' => $org, 'platform_accounts.platform_id' => $platform_id
            ])
            ->select(['platform_accounts.id', 'platform_accounts.platform_id', 'platform_lookup.auth_endpoint', 'platform_accounts.id', 'platform_accounts.account_name', 'platform_lookup.platform_image', 'platform_accounts.status'])->get();


        return ['data' => $platform];
    }
    public function getPlatformIdByName($platform_id_name)
    {
        $PlatformID = NULL;
        $response = $this->cache->get_or_set($platform_id_name); //get platform_id_name by key if value available
        if ($response) {
            $PlatformID = $response;
        } else {
            $find = PlatformLookup::select('id')->where([['platform_id', '=', $platform_id_name], ['status', '=', 1]])->first();

            if ($find) {
                $PlatformID = $find->id;
                $this->cache->get_or_set($platform_id_name, $PlatformID, 604800); //set key and value pair, currently we have pass 7 days as seconds
            }
        }

        return $PlatformID;
    }
    public function getPlatformNameByID($platform_id)
    {

        $lookup = $this->mobj->getFirstResultByConditions('platform_lookup', ['id' => $platform_id, 'status' => 1], ['platform_id']);
        $pid = NULL;
        if ($lookup) {
            $pid = $lookup->platform_id;
        }
        return $pid;
    }

    public function getPlatformConnTypeByName($platform_id_name)
    {
        $PlatformConnType = NULL;
        $find = PlatformLookup::select('allow_direct_connection')->where([['platform_id', '=', $platform_id_name], ['status', '=', 1]])->first();
        if ($find) {
            $PlatformConnType = $find->allow_direct_connection;
        }
        return $PlatformConnType;
    }

    public function getObjectId($platform_objects_name)
    {
        $objectID = NULL;
        $object = $this->cache->get_or_set($platform_objects_name); //get object id by key if value available
        if ($object) {
            $objectID = $object;
        } else {
            $object = PlatformObject::select('id')->where([['name', '=', $platform_objects_name], ['status', '=', 1]])->first();
            if ($object) {
                $objectID = $object->id;
                $this->cache->get_or_set($platform_objects_name, $objectID, 604800); //set key and value pair, currently we have pass 7 days as seconds
            }
        }
        return $objectID;
    }

    //get objectNameById
    public function getObjectNameById($platform_objects_id)
    {
        $objectName = NULL;
        $object = $this->cache->get_or_set($platform_objects_id); //get object name by id if value available
        if ($object) {
            $objectName = $object;
        } else {
            $object = PlatformObject::select('name')->where([['id', '=', $platform_objects_id], ['status', '=', 1]])->first();
            if ($object) {
                $objectName = $object->name;
                $this->cache->get_or_set($platform_objects_id, $objectName, 604800); //set key and value pair, currently we have pass 7 days as seconds
            }
        }

        return $objectName;
    }

    /* Find Customer By Email */
    public function findCustomerByEmail($email, $user_id, $platform_id, $user_integration_id)
    {
        $find = DB::table('platform_customer')->select('id', 'api_customer_id', 'api_customer_code', 'email')->where(['email' => $email, 'user_integration_id' => $user_integration_id, 'platform_id' => $platform_id, 'type' => 'Customer', 'user_id' => $user_id, 'is_deleted' => 0])->first();
        if ($find) {
            return $find;
        } else {
            return false;
        }
    }

    /* Find Customer By Email */
    public function findCustomerByName($customer_name, $userID, $platformId, $userIntegrationId, $type = "Customer")
    {
        $find = DB::table('platform_customer')->select('email', 'id', 'api_customer_id')->where([['user_id', '=', $userID], ['platform_id', '=', $platformId], ['user_integration_id', '=', $userIntegrationId], ['customer_name', '=', $customer_name], ['type', '=', $type], ['is_deleted', '=', 0]])->first();

        if ($find) {
            return $find;
        } else {
            return false;
        }
    }

    /* Find Customer By CustomerID */
    public function findCustomerByCustomerID($CustomerId, $userID, $platformId, $userIntegrationId)
    {

        $find = DB::table('platform_customer')->select('email', 'id', 'api_customer_id')->where([['user_id', '=', $userID], ['platform_id', '=', $platformId], ['user_integration_id', '=', $userIntegrationId], ['api_customer_id', '=', $CustomerId]])->first();

        if ($find) {
            return $find;
        } else {
            return false;
        }
    }
    /* Find Customer By CustomerID or Email */
    public function findCustomerByCustomerIDOrEmail($CustomerId, $Email, $userID, $platformId, $userIntegrationId)
    {

        $find = DB::table('platform_customer')->select('email', 'id', 'api_customer_id')->where([['user_id', '=', $userID], ['platform_id', '=', $platformId], ['user_integration_id', '=', $userIntegrationId]])->where(function ($query) use ($CustomerId, $Email) {
            $query->where('api_customer_id', '=', $CustomerId)
                ->orWhere('email', '=',  $Email);
        })->first();

        if ($find) {
            return $find;
        } else {
            return false;
        }
    }
    /* Covert Encrypt Values */
    public function ConvertEncryptValues($platformId)
    {

        $find = DB::table('platform_accounts')->select('id', 'app_id', 'app_secret',  'refresh_token', 'access_token',  'access_key')->where('platform_id',  $platformId)->get();

        if (!empty($find)) {
            $refresh_token = $app_secret = $app_id = $access_token = $access_key = NULL;

            foreach ($find as $key => $value) {
                if (isset($value->app_id)) {
                    $app_id = base64_decode($value->app_id);

                    $app_id =  $this->mobj->encrypt_decrypt($app_id, 'encrypt');
                }
                if (isset($value->app_secret)) {
                    $app_secret = base64_decode($value->app_secret);

                    $app_secret =  $this->mobj->encrypt_decrypt($app_secret, 'encrypt');
                }
                if (isset($value->refresh_token)) {
                    $refresh_token = base64_decode($value->refresh_token);
                    $refresh_token =  $this->mobj->encrypt_decrypt($refresh_token, 'encrypt');
                }
                if (isset($value->access_token)) {
                    $access_token = base64_decode($value->access_token);
                    $access_token =  $this->mobj->encrypt_decrypt($access_token, 'encrypt');
                }
                if (isset($value->access_key)) {
                    $access_key = base64_decode($value->access_key);
                    $access_key =  $this->mobj->encrypt_decrypt($access_key, 'encrypt');
                }

                $this->mobj->makeUpdate('platform_accounts', [
                    'refresh_token' => $refresh_token,
                    'access_token' => $access_token,
                    'app_secret' => $app_secret,
                    'access_key' => $access_key,
                    'app_id' => $app_id,
                ], ['id' => $value->id]);
            }
        }
    }
    public function ConvertEncryptValuesForApp($ID)
    {

        $find = DB::table('platform_api_app')->select('id', 'app_ref', 'client_id', 'client_secret')->where('id', $ID)->get();

        if (!empty($find)) {
            $app_ref = $client_id = $client_secret = NULL;
            foreach ($find as $key => $value) {

                if (isset($value->app_ref)) {
                    $app_ref = base64_decode($value->app_ref);

                    $app_ref =  $this->mobj->encrypt_decrypt($app_ref, 'encrypt');
                }
                if (isset($value->client_id)) {
                    $client_id = base64_decode($value->client_id);
                    $client_id =  $this->mobj->encrypt_decrypt($client_id, 'encrypt');
                }
                if (isset($value->client_secret)) {
                    $client_secret = base64_decode($value->client_secret);
                    $client_secret =  $this->mobj->encrypt_decrypt($client_secret, 'encrypt');
                }


                $this->mobj->makeUpdate('platform_api_app', [
                    'app_ref' => $app_ref,
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                ], ['id' => $value->id]);
            }
        }
    }

    public function getPlatformFlowDetail($platform_workflow_rule_id)
    {
        $platform_workflow_rule = DB::table('platform_workflow_rule')
            ->join('platform_events as source_event', 'platform_workflow_rule.source_event_id', 'source_event.id')
            ->join('platform_events as destination_event', 'platform_workflow_rule.destination_event_id', 'destination_event.id')
            ->join('platform_lookup as source', 'source_event.platform_id', 'source.id')
            ->join('platform_lookup as destination', 'destination_event.platform_id', 'destination.id')
            ->select('platform_workflow_rule.id', 'platform_workflow_rule.platform_integration_id', 'platform_workflow_rule.status', 'source_event.event_description as sourceEvent', 'destination_event.event_description as destinationEvent', DB::raw("CONCAT(source_event.event_description,' to ',destination_event.event_description) AS full_name"), 'source_event.event_name as sourceEventType', 'destination_event.event_name as destEventType', 'source.platform_name as source_platform_name', 'source.id as source_platform_id', 'destination.platform_name as destination_platform_name', 'destination.id as destination_platform_id', 'platform_workflow_rule.tooltip_text')
            ->where('platform_workflow_rule.id', $platform_workflow_rule_id)
            ->first();
        return $platform_workflow_rule;
    }
    /* Get Account Credentials */
    public function GetAccountCredentials($primaryID = NULL, $account_name = NULL, $user_id = NULL, $platform_id = NULL)
    {
        if ($primaryID) {

            $details = PlatformAccount::find($primaryID);
        }
    }

    public function getPlatformIdsByPrimaryIds($ids)
    {
        $ids = explode(',', $ids);
        $platformId = Platform::whereIn('id', $ids)->pluck('platform_id')->toArray();
        return $platformId;
    }
}
