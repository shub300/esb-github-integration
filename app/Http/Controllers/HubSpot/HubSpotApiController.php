<?php
namespace App\Http\Controllers\HubSpot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Models\PlatformAccount;
use App\Http\Controllers\HubSpot\Api\HubSpotApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformCustomer;
use App\Models\PlatformTicket;
use App\Models\PlatformTicketAttachment;
use App\Models\PlatformTicketReply;
use App\Models\PlatformWebhookInformation;
use CURLFile;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use function GuzzleHttp\json_decode;

class HubSpotApiController extends Controller
{
    public $mobj = '';
    public $HubSpotApi = '';
    public $ConnectionHelper = '';
    public $FieldMappingHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platformId = '';
    public $client_id = "aeb05c9b-5f37-49e0-bad1-b7a7f976ed04";//"05633221-26f5-4a61-ab81-210727099f51";//
    public $client_secret = "07e0a109-ef27-4917-8b87-c2675fd00829";//"167aab24-e62d-4fa4-b70e-5f73cff66e1e";//
    public $app_id = "1868272";//"1423282";//
    public $hApiKey = "698137f9-efa2-43b7-9d2b-af1c18f53a78";//"eu1-afe7-9743-4279-90ca-4e5f46a47bc1";//
    public static $myPlatform = 'hubspot';

    /**
        * Create a new controller instance.
        *
        * @return void
    */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->HubSpotApi = new HubSpotApi();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->Logger = new Logger();
        $this->WorkflowSnippet = new WorkflowSnippet();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function InitiateHubSpotAuth(Request $request)
    {
        $platform = 'hubspot';
        return view("pages.apiauth.auth_hubspot", compact('platform'));
    }

    public function ConnectHubSpot(Request $request)
    {
        $validator = Validator::make($request->all(), ['account_name'=>'required']);
        if($this->mobj->checkHtmlTags($request->all())) {
            return back()->with('error', Lang::get('tags.validate'));
        }

        if($validator->fails()) {
            return back()->withErrors($validator);
        } else {
            $account_name = trim($request->account_name);
            //to check whether given account is already in use or not.
            $checkExistingAccount = PlatformAccount::where( ['platform_id' => $this->platformId, 'account_name' => $account_name] )->first();
            if( $checkExistingAccount ){
                return back()->with('error', 'Given details are already in use, Try with other details.');
            }

            $platform_api_app = true;//PlatformApiApp::where( ['platform_id' => $this->platformId] )->first();//'client_id', 'client_secret']);
            if($platform_api_app){
                $redirect_uri = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerHubSpot'));
                $state_i = Auth::user()->id."-".$account_name;
                if( $this->client_id && $this->client_secret ) {
                    //https://app.hubspot.com/oauth/authorize?client_id=aeb05c9b-5f37-49e0-bad1-b7a7f976ed04&redirect_uri=https://esb.apiworx.net/RedirectHandlerHubSpot&scope=tickets%20crm.lists.read%20crm.objects.contacts.read%20crm.objects.contacts.write%20crm.schemas.contacts.read%20crm.lists.write%20crm.schemas.contacts.write
                    //tickets%20crm.lists.read%20crm.objects.contacts.read%20crm.objects.contacts.write%20crm.schemas.contacts.read%20crm.lists.write%20crm.schemas.contacts.write
                    //Test Live: https://app-eu1.hubspot.com/oauth/authorize?client_id=05633221-26f5-4a61-ab81-210727099f51&redirect_uri=https://esb.apiworx.net/RedirectHandlerHubSpot&scope=files%20tickets%20sales-email-read%20forms-uploaded-files%20crm.lists.read%20crm.objects.contacts.read%20crm.import%20settings.users.write%20crm.objects.contacts.write%20files.ui_hidden.read%20settings.users.read%20crm.schemas.contacts.read%20media_bridge.read%20crm.lists.write%20crm.schemas.contacts.write%20crm.objects.owners.read%20settings.users.teams.write%20settings.users.teams.read%20crm.export
                    $url = "https://app.hubspot.com/oauth/authorize?client_id=".$this->client_id."&redirect_uri=".$redirect_uri."&scope=tickets%20crm.lists.read%20crm.objects.contacts.read%20crm.objects.contacts.write%20crm.schemas.contacts.read%20crm.lists.write%20crm.schemas.contacts.write&state=".$state_i;//tickets%20crm.objects.contacts.read%20crm.objects.contacts.write
                    return redirect($url);
                } else {
                    Session::put('auth_msg', 'App config not found');
                    echo '<script>window.close();</script>';
                }
            }
            else
            {
                $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param Request $request
     * @return void
     *
     * https://app.hubspot.com/oauth/authorize?client_id=aeb05c9b-5f37-49e0-bad1-b7a7f976ed04&redirect_uri=https://esb.apiworx.net/RedirectHandlerHubSpot&scope=tickets
     */
    public function RedirectHandlerHubSpot(Request $request)
    {
        date_default_timezone_set('UTC');
        if(isset($request->code))
        {
            $platform_api_app = true;//PlatformApiApp::where( [ 'platform_id' => $this->platformId] )->first();//['client_id', 'client_secret']);
            if($platform_api_app)
            {
                $code = $request->code;
                $redirect_url = $this->mobj->makeUrlHttpsForProd( url('/RedirectHandlerHubSpot') );
                $state = $request->state;
                $state_arr = explode('-', $state);
                if(isset($state_arr[0]) && isset($state_arr[1]))
                {
                    // Valid request
                    $user_id = $state_arr[0];
                    $account_name = $state_arr[1]; // Account Code
                    if(isset($state_arr[0]) && isset($state_arr[1]))
                    {
                        $curl_post_data = [
                            'client_id' => $this->client_id,
                            'client_secret' => $this->client_secret,
                            'code' => $code,
                            'grant_type' => 'authorization_code',
                            'redirect_uri' => $redirect_url
                        ];
                        $service_url = 'https://api.hubapi.com/oauth/v1/token';
                        $headers = ['Content-Type'=>'application/x-www-form-urlencoded'];

                        $response = $this->mobj->makeRequest('POST', $service_url, $curl_post_data, $headers, 'http');

                        Storage::append( 'HubSpot/oauth-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$response->getBody() );

                        if( json_decode( $response->getBody(), true))
                        {
                            if( $hubspot_token = json_decode($response->getBody(), true))
                            {
                                if( isset( $hubspot_token['access_token'] ) )
                                {
                                    $platform_account = PlatformAccount::where([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'account_name' => $account_name
                                    ])
                                    ->first();

                                    if( !$platform_account ){
                                        $platform_account = new PlatformAccount();
                                    }

                                    $platform_account->user_id = $user_id;
                                    $platform_account->platform_id = $this->platformId;
                                    $platform_account->account_name = $account_name;
                                    $platform_account->app_id = $this->mobj->encrypt_decrypt( $this->client_id );
                                    $platform_account->app_secret = $this->mobj->encrypt_decrypt( $this->client_secret );
                                    $platform_account->refresh_token = $this->mobj->encrypt_decrypt( $hubspot_token['refresh_token'] );
                                    $platform_account->access_token = $this->mobj->encrypt_decrypt( $hubspot_token['access_token'] );
                                    $platform_account->access_key = $this->mobj->encrypt_decrypt( $this->app_id );
                                    $platform_account->secret_key = $this->mobj->encrypt_decrypt( $this->hApiKey );
                                    $platform_account->token_type = $hubspot_token['token_type'] ?? '';
                                    $platform_account->expires_in = $hubspot_token['expires_in'];
                                    $platform_account->api_domain = "https://api.hubapi.com/";
                                    $platform_account->token_refresh_time = time();
                                    $platform_account->save();
                                }
                                else
                                {
                                    if( isset( $hubspot_token['message'] ) ){
                                        $error = $hubspot_token['message'];
                                    }else{
                                        $error = "Something went wrong in your account";
                                    }

                                    echo '<script>alert("'.$error.'");window.close();</script>';
                                }
                            }
                            echo '<script>window.close();</script>';
                        }
                        else
                        {
                            $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
                        }
                    }
                }
            }
        }
        else
        {
            // When code not received from HubSpot
            $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
        }
    }

    /*
     * Refresh Token
     */
    public function RefreshToken( $id )
    {
        date_default_timezone_set('UTC');
        $return_response = false;
        try{
            $platform_account = PlatformAccount::select('id', 'app_id', 'refresh_token', 'app_secret', 'api_domain')
                                ->where( ['id' => $id, 'platform_id' => $this->platformId] )
                                ->first();
            if($platform_account)
            {
                $response = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, 'oauth/v1/token', [], true );
                Storage::append( 'HubSpot/RefreshToken-'.$id.'/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $response ) );
                if( isset( $response['access_token'] ) )
                {
                    $platform_account->access_token = $this->mobj->encrypt_decrypt( $response['access_token'] );
                    $platform_account->refresh_token = $this->mobj->encrypt_decrypt( $response['refresh_token'] );
                    $platform_account->token_type = $response['token_type'];
                    $platform_account->expires_in = $response['expires_in'];
                    $platform_account->token_refresh_time = time();
                    $platform_account->save();
                    $return_response = $response['access_token'];
                }
                else
                {
                    if(isset($response['message'])){
                        $return_response = $response['message'];
                    } else {
                        $return_response = "Something went wrong in your account";
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($id . ' - HubSpotApiController - RefreshToken - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     *
     */
    public function getTicketStatus( $user_id, $user_integration_id ){

        $return_data = true;
        try
        {
            $ticket_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name'=>'ticket_status'], ['id']);

            if($ticket_object)
            {
                $this->mobj->makeUpdate('platform_object_data', ['status'=>0], [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ticket_object->id,
                    'status' => 1
                ]);

                $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

                if($platform_account)
                {
                    $response = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, "crm/v3/pipelines/tickets" );
                    // Storage::append( 'HubSpot/'.$user_integration_id.'/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $response ) );

                    if( isset( $response['results'] ) ){
                        foreach( $response['results'][0]['stages'] as $status )
                        {
                            $ticketStatus = [
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_object_id' => $ticket_object->id,
                                'api_id' => $status['id'],
                                'name' => $status['label'],
                                'api_code' => Strtoupper ( $this->convertStringToSlug( $status['label'] ) ),
                                'description' => $status['label'],
                                'status' => 1
                            ];

                            $platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data',
                                [
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_object_id' => $ticket_object->id,
                                    'api_id' => $status['id'],
                                    'api_code' => Strtoupper ( $this->convertStringToSlug( $status['label'] ) ),
                                ],
                                ['id']
                            );

                            if($platform_object_data) {
                                $this->mobj->makeUpdate('platform_object_data', $ticketStatus, ['id'=>$platform_object_data->id]);
                            } else {
                                $this->mobj->makeInsert('platform_object_data', $ticketStatus);
                            }
                        }
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id.' - HubSpotApiController - getTicketStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    /*
     * @Function:        <getTickets>
     * @Author:          Gautam Kakadiya
     * @Created On:      <08-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain tickets matching the passed criteria>
     * @URL
     *      $url = "crm/v3/objects/tickets?associations=note&properties=hs_attachment_ids";
     *      $url = "crm/v3/objects/notes?limit=10&properties=hs_note_body&properties=hs_attachment_ids&associations=ticket";
     * @Returns:         <   >
    */
    public function getTickets( $user_id, $user_integration_id, $user_workflow_rule_id=0, $source_platform_id=0, $is_initial_sync=0 ){

        $return_response = true;
        $sync_object_id = $this->ConnectionHelper->getObjectId('platform_ticket');
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

        if( $platform_account ){
            try{
                if ($is_initial_sync) {
                    $this->CreateOrDeleteWebhook($user_id, $user_integration_id, 1 );
                } else {
                    $after = 0;
                    $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'url_name' => 'hubspot_get_ticket_limit'
                        ],
                        ['url', 'id']);

                    if ( $limit ) {
                        $after = $limit->url;
                    }

                    $url = "crm/v3/objects/tickets?after=".$after."&limit=50";//?associations=note&after=".$after;
                    $response = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, $url );
                    // Storage::append( 'HubSpot/'.$user_integration_id.'/getTickets/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."]: ".json_encode( $response ) );
                    
                    if ( isset( $response['results'] ) && COUNT( $response['results'] ) > 0) {
                        foreach( $response['results'] as $ticketDetails ){
                            $this->getTicketDetails( $user_id, $ticketDetails['id'], $user_integration_id, $user_workflow_rule_id, $source_platform_id, $sync_object_id );
                        }

                        $after = 0;
                        if( isset( $response['paging'] ) ){
                            $after = $response['paging']['next']['after'];
                        }

                        if ( !$limit ) {
                            $this->mobj->makeInsert('platform_urls', [
                                'user_id' => $user_id, 
                                'user_integration_id' => $user_integration_id, 
                                'platform_id' => $this->platformId, 
                                'url' => 0, 
                                'url_name' => 'hubspot_get_ticket_limit'
                            ]);
                        } else {
                            $this->mobj->makeUpdate('platform_urls', ['url' => $after], ['id' => $limit->id]);
                        }

                    } else if( isset( $response['status'] ) && $response['status'] == "error" ){
                        $return_response = $response['message'];
                    }
                }
            } catch ( Exception $e) {
                Log::error( $user_integration_id."-- ExecuteHubSpotEvents getTickets -->".$e->getMessage());
                $return_response = $e->getMessage();
            }
        } else {
            $return_response = false;
        }

        return $return_response;
    }

    /*
     * @Function:        <getTicketDetails>
     * @Author:          Gautam Kakadiya
     * @Created On:      <08-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain a specific ticket>
     * @Returns:         < >
    */
    public function getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $sync_object_id, $linked_id=null, $customerArr=[] ){

        $return_response = true;
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

        if( $platform_account ){
            try{

                $ticketDetails = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, 'crm/v3/objects/tickets/'.$ticket_id);
                if ( isset( $ticketDetails['id'] ) ) {
                    $this->storeTicketDetails( $ticketDetails, $platform_account, $user_id, $user_integration_id, true, $linked_id, $customerArr );
                }  else {
                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', 0, $ticketDetails['api_error'] );
                }
            } catch ( Exception $e) {
                Log::error( $user_integration_id."-- ExecuteHubSpotEvents getTicketDetails -->".$e->getMessage());
                $return_response = $e->getMessage();
            }
        } else {
            $return_response = false;
        }

        return $return_response;
    }

    /*
     * @Function:        <storeTicketDetails>
     * @Author:          Gautam Kakadiya
     * @Created On:      <08-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain ticket detail the passed criteria>
     * @Returns:         <   >
    */
    public function storeTicketDetails( $ticketDetails, $platform_account, $user_id, $user_integration_id, $isJson=false, $linked_id=null, $customerArr=[] ){

        $return_response = true;
        try{
            $ticket = PlatformTicket::where( [
                // "user_id" => $user_id,
                "api_ticket_id" => $ticketDetails['id'],
                "platform_id" => $this->platformId,
                "user_integration_id" => $user_integration_id
            ] )->first();

            $createOrUpdate = 1;

            if( !$ticket ){
                $ticket = new PlatformTicket();
                $ticket->sync_status = PlatformStatus::READY;
            } else if ( strtotime( $ticketDetails['properties']['hs_lastmodifieddate'] ) == strtotime( $ticket->ticket_update_date ) ){
                $createOrUpdate = 0;
            } else {
                $ticket->sync_reply_status = PlatformStatus::READY;
            }

            if( $createOrUpdate == 0 ){
                return true;
            }

            $ticket->platform_id = $this->platformId;
            $ticket->user_id = $user_id;
            $ticket->user_integration_id = $user_integration_id;

            if( isset( $customerArr['customer_id'] ) && $customerArr['customer_id'] > 0 ){
                $ticket->customer_id = $customerArr['customer_id'];
            }

            $ticket->name = null;
            $ticket->email = null;
            $ticket->subject = $ticketDetails['properties']['subject'];
            $ticket->ticket_status = $ticketDetails['properties']['hs_pipeline_stage'];
            $ticket->priority = $ticketDetails['properties']['hs_ticket_priority'] ?? '';
            $ticket->requester_type = null;
            $ticket->api_ticket_id = $ticketDetails['id'];
            $ticket->ticket_number = 0;
            $ticket->ticket_date = $ticketDetails['properties']['createdate'];
            $ticket->ticket_update_date = $ticketDetails['properties']['hs_lastmodifieddate'];

            if( $linked_id ){
                $ticket->linked_id = $linked_id;
            }

            if( env('APP_ENV') != 'prod' ){
                $ticket->json_object = json_encode( $ticketDetails );
            }

            $ticket->save();

            if( $linked_id != null ){
                $ticketLinked = PlatformTicket::find( $linked_id );
                $ticketLinked->linked_id = $ticket->id;
                $ticketLinked->save();
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->HubSpotApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )}",
                'Content-Type' => 'application/json'
            ])->get("https://api.hubapi.com/engagements/v1/engagements/associated/ticket/{$ticketDetails['id']}/paged");

            if ($response->ok()) {
                $data = $response->json();
                // Iterate through the engagements to find the email
                $i=0;
                foreach ($data['results'] as $engagement) {
                    $type = strtolower( $engagement['engagement']['type'] );
                    // Log::info( "HubSpot Ticket Type: ".$type );
                    $where = [
                        'platform_ticket_id' => $ticket->id,
                        'ticket_id' => $ticketDetails['id'],
                        'reply_id' => $engagement['engagement']['id'],
                        // 'type' => $type,
                    ];

                    $reply = PlatformTicketReply::where( $where )->first();

                    $sync_status = PlatformStatus::READY;
                    if( !$reply ){
                        $reply = new PlatformTicketReply();

                        $reply->platform_ticket_id = $ticket->id;
                        $reply->ticket_id = $ticketDetails['id'];
                        $reply->reply_id = $engagement['engagement']['id'];
                        $reply->name = "-";
                        $reply->email = "-";
                        $reply->requestor_type = "-";
                        $reply->message = $engagement['engagement']['bodyPreview'] ?? '';
                        $reply->date = date( 'Y-m-d h:i:s', $engagement['engagement']['lastUpdated'] );
                        $reply->sync_status = $sync_status;
                        $reply->type = $type.'s';
                        $reply->save();


                        if ($type === 'note') {
                            $associationsResult = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, 'crm/v3/objects/notes/'.$engagement['engagement']['id'].'?properties=hs_note_body&properties=hs_attachment_ids' );
                            if( $associationsResult['api_status'] == "success" ){
                                $associationsResult['replyid'] = $engagement['engagement']['id'];
                                $associationsResult['index'] = $i++;
                                $this->storeTicketAttachment( $platform_account, $ticket->id, $associationsResult, $user_integration_id );
                            }
                        }
                    }
                }
            } else {
                // Handle error response
            }

        } catch (Exception $e) {
            Log::error( $user_integration_id."<--ExecuteHubSpotEvents storeTicketDetails -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * @Function:        <>
     * @Author:          Gautam Kakadiya
     * @Created On:      <08-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <>
     * @Returns:         <   >
    */
    public function storeTicketAttachment( $account, $ticket_id=0, $data=[], $user_integration_id ){
        $return_response = true;

        try{
            $where['platform_ticket_id'] = $ticket_id;
            PlatformTicketAttachment::where( $where )->update( ['status' => 0] );

            $where['index'] = $data['index'];
            $attachment = PlatformTicketAttachment::where( $where )->first();

            if( !$attachment ){
                $attachment = new PlatformTicketAttachment();

                $filename = $filepath = "";
                if( $data['properties']['hs_attachment_ids'] ){
                    $attachmentResult = $this->HubSpotApi->CheckAPIResponse( "GET", $account, 'files/v3/files/'.$data['properties']['hs_attachment_ids'] );
                    $filename = $attachmentResult['name'].".".$attachmentResult['extension'];
                    $filepath = $attachmentResult['url'];
                }

                if( $filename != "" ){
                    $attachment->platform_ticket_id = $ticket_id;
                    $attachment->reply_id = $data['replyid'] ?? null;
                    $attachment->filename = $filename;
                    $attachment->file_path = $filepath;
                    $attachment->description = $data['properties']['hs_note_body'];
                    $attachment->index = $data['index'];
                    $attachment->status = 1;
                    $attachment->save();
                }
            }
        } catch ( Exception $e ) {
            Log::error($user_integration_id."--HubSpotApiController storeTicketAttachment-->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * @Function:        <>
     * @Author:          Gautam Kakadiya
     * @Created On:      <08-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <>
     * @Returns:         < >
    */
    public function replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id=0 ){

        $return_response = true;
        try
        {
            $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if( $platform_account ){
                $limit = 20;
                $offset = 0;

                $where['platform_id'] = $source_platform_id;
                $where['user_integration_id'] = $user_integration_id;
                $where['sync_reply_status'] = PlatformStatus::READY;

                if( $record_id ){
                    $where['id'] = $record_id;
                    $where['sync_reply_status'] = PlatformStatus::FAILED;
                }
    
                $platformTicketArr = PlatformTicket::
                    where( $where )
                    ->where( 'linked_id', '!=', 0 )
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                if( count( $platformTicketArr ) > 0){

                    $sync_object_id = $this->ConnectionHelper->getObjectId('platform_ticket');
                    $SourceOrDestination = "source";
                    $platform_workflow_rule = $this->ConnectionHelper->getPlatformFlowDetail($user_workflow_rule_id);
                    if($platform_workflow_rule && $platform_workflow_rule->destination_platform_id == $this->platformId)
                    {
                        $SourceOrDestination = "destination";
                    }

                    $ticket_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name'=>'ticket_status'], ['id']);

                    foreach( $platformTicketArr as $platform_ticket ){
                        $sourcePlatformAccount = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $source_platform_id);

                        if( $sourcePlatformAccount ){

                            $hs_pipeline_stage = null;

                            /*----------------Start to find order status----------------*/
                            $ticket_status_name = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $source_platform_id,
                                    'platform_object_id' => $ticket_object->id,
                                    'name' => $platform_ticket->ticket_status,
                                    'status' => 1
                                ],
                                ['api_id']
                            );

                            if($ticket_status_name)
                            {
                                $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $ticket_status_name->api_id, "single", $SourceOrDestination);
                                if($map_ticket_status)
                                {
                                    $hs_pipeline_stage = $map_ticket_status->api_id;
                                }
                            }
                            else
                            {
                                $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $platform_ticket->ticket_status, "single", $SourceOrDestination);
                                if($map_ticket_status)
                                {
                                    $hs_pipeline_stage = $map_ticket_status->api_id;
                                }
                            }

                            $updatePlatformTicketStatus = PlatformTicket::find( $platform_ticket->id );
                            // if( $hs_pipeline_stage != null ){
                                $sourceTicketArr = PlatformTicket::
                                    select( 'api_ticket_id', 'id' )
                                    ->where( "id", $platform_ticket['linked_id'] )
                                    ->first();

                                $ticketid = $sourceTicketArr->api_ticket_id;
                                $httpBuildQueryArr = [
                                    "properties" => [
                                        "hs_pipeline" => 0,
                                        "hs_pipeline_stage" => $hs_pipeline_stage,
                                        "hs_ticket_priority" => strtoupper( $platform_ticket['priority'] ),
                                        "subject" => $platform_ticket['subject']
                                    ]
                                ];

                                $response = $this->HubSpotApi->CheckAPIResponse( "PATCH", $platform_account, "crm/v3/objects/tickets/".$ticketid, json_encode( $httpBuildQueryArr ) );//['id'] = $ticketid;//
                                // Storage::append( 'HubSpot/'.$user_integration_id.'/replyTicket/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $httpBuildQueryArr )." : ".json_encode( $response ) );
                                
                                if ( isset( $response['id'] ) && $response['id'] > 0){
                                    //get ticket replay & update it
                                    $getPlatformTicketReplyArr = PlatformTicketReply::where( [
                                        'ticket_id' => $platform_ticket->api_ticket_id,
                                        'platform_ticket_id' => $platform_ticket->id,
                                        'sync_status' => PlatformStatus::READY,
                                        'linked_id' => 0,
                                    ])
                                    ->where( 'reply_id', '!=', 0)
                                    ->orderBy( 'reply_id' )
                                    ->get();

                                    foreach( $getPlatformTicketReplyArr as $platform_ticket_reply ){

                                        if( $platform_ticket_reply->message != "" ){
                                            $getPlatformTicketAttachmentArr = PlatformTicketAttachment::
                                            select( 'filename', 'file_path' )
                                            ->where( [
                                                'ticket_id' => $platform_ticket_reply->ticket_id,
                                                'platform_ticket_id' => $platform_ticket_reply->platform_ticket_id,
                                                'reply_id' => $platform_ticket_reply->reply_id
                                            ])
                                            ->where( 'filename', "!=", "" )
                                            ->get();

                                            $updatePlatformTicketReplyStatus = PlatformTicketReply::find( $platform_ticket_reply->id );
                                            $hs_timestamp = ( $platform_ticket_reply->date === "0000-00-00 00:00:00" ) ? time() : strtotime( $platform_ticket_reply->date );
                                            $hs_timestamp = date("Y-m-d", $hs_timestamp).'T'.date("h:i:s", $hs_timestamp ).'Z';
                                            // Storage::append( 'HubSpot/PlatformTicketReply-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$platform_ticket_reply->id." - ".$hs_timestamp );
                                            
                                            foreach( $getPlatformTicketAttachmentArr as $platform_ticket_attachment ){
                                                $createAttachment = $this->CreateHubSpotAttachment( $user_integration_id, $platform_ticket_attachment->filename, $platform_ticket_attachment->file_path, $hs_timestamp );

                                                if( isset( $createAttachment['status'] ) && $createAttachment['status'] == "error" ){
                                                    return $createAttachment['message'];
                                                }else{
                                                    $httpBuildQueryArr = [
                                                        "properties" => [
                                                            "hs_timestamp" => $hs_timestamp,
                                                            "hs_attachment_ids" => $createAttachment['objects'][0]['id'],
                                                        ],
                                                        "associations" => [
                                                            [
                                                                "to" => [
                                                                    "id" => $ticketid
                                                                ],
                                                                "types" => [
                                                                    [
                                                                        "associationCategory" => "HUBSPOT_DEFINED",
                                                                        "associationTypeId" => 18
                                                                    ]
                                                                ]
                                                            ]
                                                        ]
                                                    ];

                                                    // Storage::append( 'HubSpot/PlatformTicketReply-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": Query ".json_encode( $httpBuildQueryArr ) );
                                                    $attachmentResponse = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "crm/v3/objects/notes", json_encode( $httpBuildQueryArr ) );
                                                    // Storage::append( 'HubSpot/PlatformTicketReply-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": Result ".json_encode( $attachmentResponse ) );
                                                }
                                            }

                                            $httpBuildQueryArr = [
                                                "properties" => [
                                                    "hs_timestamp" => $hs_timestamp,
                                                    "hs_note_body" => $platform_ticket_reply->message,
                                                ],
                                                "associations" => [
                                                    [
                                                        "to" => [
                                                            "id" => $ticketid
                                                        ],
                                                        "types" => [
                                                            [
                                                                "associationCategory" => "HUBSPOT_DEFINED",
                                                                "associationTypeId" => 18
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ];

                                            $replyResponse = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "crm/v3/objects/notes", json_encode( $httpBuildQueryArr ) );

                                            $updatePlatformTicketReplyStatus->sync_status = PlatformStatus::SYNCED;
                                            $linked_id = 0;
                                            if( $replyResponse['api_status'] != "success" ){
                                                $updatePlatformTicketReplyStatus->sync_status = PlatformStatus::FAILED;
                                            } else {
                                                $ticketReply = new PlatformTicketReply();
                                                $ticketReply->ticket_id = $sourceTicketArr->api_ticket_id;
                                                $ticketReply->platform_ticket_id = $sourceTicketArr->id;
                                                $ticketReply->reply_id = $replyResponse['id'];
                                                $ticketReply->message = $replyResponse['properties']['hs_body_preview'];
                                                $ticketReply->date = date( 'Y-m-d h:i:s' );
                                                $ticketReply->linked_id = $platform_ticket_reply->id;
                                                $ticketReply->sync_status = PlatformStatus::SYNCED;
                                                $ticketReply->save();
                                                $linked_id = $ticketReply->id;
                                            }
                                            $updatePlatformTicketReplyStatus->linked_id = $linked_id;
                                            $updatePlatformTicketReplyStatus->save();
                                        }
                                    }

                                    $updatePlatformTicketStatus->sync_reply_status = PlatformStatus::SYNCED;
                                    $updatePlatformTicketStatus->sync_status = PlatformStatus::SYNCED;
                                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $platform_ticket->id, null );
                                } else {
                                    $updatePlatformTicketStatus->sync_reply_status = PlatformStatus::FAILED;
                                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, $response['api_error'] );
                                }
                            $updatePlatformTicketStatus->save();
                        }  else {
                            Log::error( $user_integration_id."-- ExecuteHubSpotEvents replyTicket--> Source Account does not exist.");
                            $return_response = false;
                        }
                    }
                }
            } else {
                $return_response = false;
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteHubSpotEvents replyTicket -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     *
     */
    public function createTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id=0 ){
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId );

        if( $platform_account ){
            $return_response = true;
            $limit = 20;
            $offset = 0;

            $where['platform_id'] = $source_platform_id;
            $where['user_integration_id'] = $user_integration_id;
            $where['sync_status'] = PlatformStatus::READY;
            $where['linked_id'] = null;

            if( $record_id ){
                $where['id'] = $record_id;
                $where['sync_status'] = PlatformStatus::FAILED;
            }

            $platformTicketArr = PlatformTicket::
                where( $where )
                ->offset($offset)
                ->limit($limit)
                ->get();

            if( count($platformTicketArr) > 0){
                try{
                    $sync_object_id = $this->ConnectionHelper->getObjectId('platform_ticket');

                    $SourceOrDestination = "source";
                    $platform_workflow_rule = $this->ConnectionHelper->getPlatformFlowDetail($user_workflow_rule_id);
                    if($platform_workflow_rule && $platform_workflow_rule->destination_platform_id == $this->platformId){
                        $SourceOrDestination = "destination";
                    }

                    $ticket_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name'=>'ticket_status'], ['id']);

                    foreach( $platformTicketArr as $platform_ticket ){

                        //check customer contact exist or not
                        $data['company'] = $platform_ticket['deptname'];
                        $data['email'] = $platform_ticket['email'];
                        $name = explode( " ", $platform_ticket['name']);
                        $data['first_name'] = $name[0] ?? '';
                        $data['last_name'] = $name[1] ?? '';
                        $data['phone'] = '';
                        $customerArr = $this->checkOrCreateCustomer( $user_id, $user_integration_id, $data );

                        if( true ){//$customerArr['contact_id'] > 0
                            $sourcePlatformAccount = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $source_platform_id);
                            if( $sourcePlatformAccount ){

                                $hs_pipeline_stage = null;

                                /*----------------Start to find order status----------------*/
                                $ticket_status_name = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $source_platform_id,
                                        'platform_object_id' => $ticket_object->id,
                                        'name' => $platform_ticket->ticket_status,
                                        'status' => 1
                                    ],
                                    ['api_id']
                                );
                                if($ticket_status_name) {
                                    $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $ticket_status_name->api_id, "single", $SourceOrDestination);
                                    if($map_ticket_status) {
                                        $hs_pipeline_stage = $map_ticket_status->api_id;
                                    }
                                } else {
                                    $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $platform_ticket->ticket_status, "single", $SourceOrDestination);
                                    if($map_ticket_status)
                                    {
                                        $hs_pipeline_stage = $map_ticket_status->api_id;
                                    }
                                }

                                $updateTicketStatus = PlatformTicket::find( $platform_ticket->id );
                                if( $hs_pipeline_stage != null ){

                                    if( $customerArr['contact_id'] > 0 ){
                                        $httpBuildQueryArr = [
                                            "properties" => [
                                                "content" => $platform_ticket->message,
                                                "hs_pipeline" => "0",
                                                "hs_pipeline_stage" => $hs_pipeline_stage,
                                                // "hs_ticket_category" => null,
                                                "hs_ticket_priority" => strtoupper( $platform_ticket->priority ),
                                                "subject" => $platform_ticket->subject,
                                            ],
                                            "associations" => [
                                                [
                                                    "to" => [
                                                        "id" => $customerArr['contact_id'],
                                                    ],
                                                    "types" => [
                                                        [
                                                            "associationCategory" => "HUBSPOT_DEFINED",
                                                            "associationTypeId" => 16
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ];
                                    } else {
                                        $httpBuildQueryArr = [
                                            "properties" => [
                                                "content" => $platform_ticket->message,
                                                "hs_pipeline" => "0",
                                                "hs_pipeline_stage" => $hs_pipeline_stage,
                                                // "hs_ticket_category" => null,
                                                "hs_ticket_priority" => strtoupper( $platform_ticket->priority ),
                                                "subject" => $platform_ticket->subject,
                                            ]
                                        ];
                                    }

                                    // Storage::append( 'HubSpot/'.$user_integration_id.'/CreateTicketHttp/'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] Build Query : ".json_encode( $httpBuildQueryArr ) );
                                    $ticketDetails = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "crm/v3/objects/tickets", json_encode( $httpBuildQueryArr ) );
                                    // Storage::append( 'HubSpot/'.$user_integration_id.'/CreateTicketHttp/'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] Build Response: : ".json_encode( $ticketDetails ) );

                                    if ( isset( $ticketDetails['id'] ) && $ticketDetails['id'] > 0) {
                                        //create first ticket attachment with reply id is 0 available.
                                        $updatePlatformTicketAttachmentArr = PlatformTicketAttachment::where( [
                                            'ticket_id' => $platform_ticket->api_ticket_id,//$ticketDetails['id'],
                                            'platform_ticket_id' => $platform_ticket->id,
                                            'reply_id' => 0,
                                            'status' => 1
                                        ] )->get();

                                        if( $updatePlatformTicketAttachmentArr ){
                                            foreach( $updatePlatformTicketAttachmentArr as $updatePlatformTicketAttachment ){
                                                $updatePlatformTicketAttachmentStatus = PlatformTicketAttachment::find( $updatePlatformTicketAttachment->id );

                                                $createAttachment = $this->CreateHubSpotAttachment( $user_integration_id, $updatePlatformTicketAttachment->filename, $updatePlatformTicketAttachment->file_path, $platform_ticket->ticket_update_date );
                                                if( isset( $createAttachment['status'] ) && $createAttachment['status'] == "error" ){
                                                    return $createAttachment['message'];
                                                } else {
                                                    $hs_timestamp = strtotime( $platform_ticket->ticket_update_date );
                                                    $hs_timestamp = date("Y-m-d", $hs_timestamp).'T'.date("h:i:s", $hs_timestamp ).'Z';

                                                    $httpBuildQueryArr = [
                                                        "properties" => [
                                                            "hs_timestamp" => $hs_timestamp,
                                                            "hs_attachment_ids" => $createAttachment['objects'][0]['id'],
                                                        ],
                                                        "associations" => [
                                                            [
                                                                "to" => [
                                                                    "id" => $ticketDetails['id'],
                                                                ],
                                                                "types" => [
                                                                    [
                                                                        "associationCategory" => "HUBSPOT_DEFINED",
                                                                        "associationTypeId" => 18
                                                                    ]
                                                                ]
                                                            ]
                                                        ]
                                                    ];
                                                    $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "crm/v3/objects/notes", json_encode( $httpBuildQueryArr ) );
                                                    
                                                    $updatePlatformTicketAttachmentStatus->status = 0;
                                                    $updatePlatformTicketAttachmentStatus->save();

                                                    PlatformTicketReply::where( [
                                                        'ticket_id' => $platform_ticket->api_ticket_id,
                                                        'platform_ticket_id' => $platform_ticket->id,
                                                        'sync_status' => PlatformStatus::READY,
                                                        'reply_id' => 0
                                                    ])
                                                    ->update( [
                                                        'sync_status' => PlatformStatus::SYNCED
                                                    ] );
                                                }
                                            }
                                        }

                                        $updateTicketStatus->sync_status = PlatformStatus::SYNCED;
                                        $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $platform_ticket->id, null );

                                        //Generate Linked integration
                                        $this->getTicketDetails( $user_id, $ticketDetails['id'], $user_integration_id, $user_workflow_rule_id, $source_platform_id, $sync_object_id, $platform_ticket->id, $customerArr );

                                    }  else {
                                        $updateTicketStatus->sync_status = PlatformStatus::FAILED;
                                        $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, $ticketDetails['api_error'] );
                                    }

                                } else {
                                    $updateTicketStatus->sync_status = PlatformStatus::FAILED;
                                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, 'Not define hubspot pipeline stage' );
                                }
                                $updateTicketStatus->save();
                            } else {
                                $return_response = false;
                            }
                        } else {
                            $updateTicketStatus = PlatformTicket::find( $platform_ticket->id );
                            $updateTicketStatus->sync_status = PlatformStatus::FAILED;
                            $updateTicketStatus->save();
                            $message = "Hubspot Platform does not create customer contact ".$platform_ticket->subject." (".$platform_ticket->api_ticket_id.")";
                            $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, $message );
                            $return_response = false;
                        }
                    }
                } catch ( Exception $e) {
                    Log::error( $user_integration_id."-- ExecuteHubSpotEvents createTicket -->".$e->getMessage());
                    $return_response = $e->getMessage();
                }
            }
        }

        return $return_response;
    }

    /**
     *
     */
    public function CreateHubSpotAttachment( $user_integration_id, $name, $realpath, $date='' ){

        $return_response = true;
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

        if( $platform_account ){
            try{
                if( $date == "" ){
                    $date = date( 'Y-m-d' );
                }

                $url = "https://api.hubapi.com/filemanager/api/v3/files/upload";

                $headers = [
                    'Content-Type:multipart/form-data',
                    'Authorization: Bearer '.$this->mobj->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                ];

                $upload_file = new CURLFile( $realpath, 'application/octet-stream', $name);

                $file_options = [
                    "access" => "PUBLIC_INDEXABLE",
                    "overwrite" => false,
                    "duplicateValidationStrategy" => "NONE",
                    "duplicateValidationScope" => "ENTIRE_PORTAL"
                ];

                $post_data = [
                    "file" => $upload_file,
                    "filename" => $name,
                    "options" => json_encode($file_options),
                    "folderPath" => "/",
                ];

                // Storage::append( 'HubSpot/PlatformTicketReply-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": Attachment ".json_encode( $post_data ) );

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_ENCODING, '');
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers );

                $response = curl_exec($ch);
                if (!$response) {
                    $response = curl_error($ch);
                }

                curl_close($ch);
                return json_decode( $response, 1 );
            }  catch ( Exception $e) {
                Log::error( $user_integration_id."-- ExecuteHubSpotEvents getTicketDetails -->".$e->getMessage());
                $return_response = $e->getMessage();
            }
        }else {
            $return_response = false;
        }

        return $return_response;
    }

    /* Create Webhook */
    public function CreateOrDeleteWebhook($user_id = null, $user_integration_id = null, $attempt)
    {
        $return_response = true;
        try {
            $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

            if ($platform_account) {

                if ($attempt == 1) {// create webhook
                    /* Please pass last param as 0=for staging mode and 1=for live mode */

                    $arraywebhooklist = array();
                    /* Please pass last param as if APP_ENV=stag or local then 0 for staging/local mode and APP_ENV=prod then 1=for live mode */
                    $Mode = env('APP_ENV') == 'prod' ? "prod" : "stag";
                    $targetUrl = env('APP_WEBHOOK_URL')."/hubspot/index.php?for=ticket&user_integration_id=".$user_integration_id."&environment=".$Mode;
                    $dataObject = '{"targetUrl": "'.$targetUrl.'","throttling": {"maxConcurrentRequests": 10,"period": "SECONDLY"}}';

                    // "webhooks/v3/".$platform_account->account_name."/settings";//1423282
                    $setting = $this->HubSpotApi->CheckAPIResponse( "PUT", $platform_account, "webhooks/v3/".$platform_account->access_key."/settings?hapikey=".$platform_account->secret_key, $dataObject, false, true );

                    if( isset( $setting['targetUrl'] ) ){
                        $check_already_subscribed = PlatformWebhookInformation::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'status' => 1
                        ])
                        ->pluck('description')
                        ->toArray();

                        // if ( !in_array('ticket.creation', $check_already_subscribed)){
                        //     $arraywebhooklist[] = '{"eventType": "ticket.creation","propertyName": "Ticket Creation","active": true}';
                        // }

                        if ( !in_array('ticket.deletion', $check_already_subscribed)) {
                            $arraywebhooklist[] = '{"eventType": "ticket.deletion","propertyName": "Ticket Deletion","active": true}';
                        }

                        if ( !in_array('ticket.propertyChange', $check_already_subscribed)) {
                            $arraywebhooklist[] = '{"eventType": "ticket.propertyChange","propertyName": "Ticket Property Change","active": true}';
                        }

                        if (!empty($arraywebhooklist)) {
                            $message = [];
                            foreach ($arraywebhooklist as $row) {
                                $webhook = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "webhooks/v3/".$platform_account->account_name."/subscriptions", $row );

                                if ( isset($webhook['id'])) {//success
                                    //insert webhook log
                                    $webhookdetails = [
                                        // 'user_id' => $user_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_id' => $webhook['id'],
                                        'description' => $row['eventType'],
                                        'status' => 1
                                    ];
                                    $this->mobj->makeInsert('platform_webhook_info', $webhookdetails);

                                } elseif (isset($webhook['message']) ) {//Failer
                                    $message[] = $webhook['message'];
                                }
                            }

                            if (empty($message)) {
                                $return_response = true;
                            } else {
                                $return_response = implode(" | ", $message);
                            }
                        }
                    } else if( isset( $setting['message'] ) ){
                        $return_response = $setting['message'];
                    }

                } elseif ($attempt == 2) {// delete webhook
                    $return_response = true;
                    $hookList = $this->mobj->getResultByConditions('platform_webhook_info', [
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId
                        ],
                        ['api_id'],
                        ['id' => 'asc']
                    );
                    if ($hookList->count() > 0) {
                        $hookIds = $hookList->pluck('api_id')->toArray();
                        foreach ($hookIds as $hookId) {
                            $webhook = $this->HubSpotApi->CheckAPIResponse( "DELETE", $platform_account, "/webhooks/v3/".$platform_account->access_key."/subscriptions/".$hookId );
                            if ( isset( $webhook['message'] ) ) {
                                $return_response = $webhook['message'];
                            } else {
                                $this->mobj->makeDelete('platform_webhook_info', [
                                    // 'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'api_id' => $hookId
                                ]);
                            }
                        }
                    }

                    if( $return_response == true ){
                        $this->HubSpotApi->CheckAPIResponse( "DELETE", $platform_account, "/webhooks/v3/".$platform_account->access_key."/settings" );
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error($user_integration_id . " -> HubSpotApiController -> CreateOrDeleteWebhook -> " . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Receive webhook from HS */
    public function webhookResponse(Request $request, $user_integration_id)
    {
        Log::channel('webhook')->info('HubSpot webhookResponse:'.print_r( $request->all(), true ) );

        $mainArr = $InsertData = [];
        if ($request->isMethod('post')) {
            $EventIDs = ["GET_TICKET", "GET_TICKETREPLY"];
            $integration = $this->mobj->getFirstResultByConditions('user_integrations', ['id' => $user_integration_id], ['user_id', 'platform_integration_id', 'selected_sc_account_id', 'selected_dc_account_id'], []);
            if ($integration) {
                $user_workflow_rule = DB::table('user_workflow_rule as ur')->select('e.event_id')
                    ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
                    ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
                    ->where('pr.status', 1)
                    ->where('ur.status', 1)
                    ->where('e.status', 1)
                    // ->where('ur.user_id', $integration->user_id)
                    ->where('ur.user_integration_id', $user_integration_id);

                if ($user_workflow_rule->count() > 0) {
                    $user_work_flow = $user_workflow_rule->pluck('e.event_id')->toArray();
                    /* Check whether shipment is ON or OFF */
                    if ($user_work_flow) {
                        /* Check whether product sync or order is ON or OFF */
                        $findEvents = array_intersect($EventIDs, $user_work_flow);
                        if (!empty($findEvents)) {
                            $body = $request->getContent();
                            // Log::info( "HubSpot WebhookResponse Body:".$body );
                            /* Decode Json */
                            // $result_data = json_decode($body, 1);

                            // if ($result_data && isset($result_data['id'])) {
                            //     $arr = explode(",", $result_data['id']);
                            //     $mainArr = $InsertData = [];
                            //     foreach ($arr as $val) {
                            //         if (strpos($val, "-")) {
                            //             $break_dash = explode("-", $val);
                            //             $range_ids = range($break_dash[0], $break_dash[1]);
                            //             foreach ($range_ids as $key) {
                            //                 array_push($mainArr, $key);
                            //             }
                            //         } else {
                            //             array_push($mainArr, $val);
                            //         }
                            //     }

                            //     if ($result_data['fullEvent'] == 'goods-out-note.destroyed') {
                            //         $mainArr = array_unique($mainArr);
                            //         if (!empty($mainArr)) {
                            //             $mainArr = array_unique($mainArr);
                            //             /* Get order ids for deleted shipment */
                            //             $query = PlatformOrderShipment::whereNotNull('platform_order_id')->where(['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id])->whereIn('shipment_id', $mainArr);

                            //             $platform_order_ids = $query->select('platform_order_id')->pluck('platform_order_id')->toArray();
                            //             if ($platform_order_ids) {
                            //                 $query->update(['sync_status' => "Ready"]); //Update Deleted Shipment Row

                            //                 PlatformOrder::where(['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id])->whereIn('id', $platform_order_ids)
                            //                     ->update(['is_deleted' => 1, 'sync_status' => 'Ready', 'shipment_status' => 'Ready', 'order_updated_at' => date('Y-m-d H:i:s'), 'api_updated_at' => date('Y-m-d H:i:s')]);
                            //             }

                            //             //delete unprocessed shipment
                            //             DB::table('platform_order_shipments')->whereNull('platform_order_id')->where(['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'sync_status' => 'Pending'])->whereIn('shipment_id', $mainArr)->delete();
                            //         }
                            //     } elseif ($result_data['fullEvent'] == 'drop-ship-note.modified.shipped') {
                            //         $mainArr = array_unique($mainArr);
                            //         if (!empty($mainArr)) {
                            //             $mainArr = array_unique($mainArr);
                            //             /* Set Delete if duplicate ids found for same integration*/
                            //             DB::table('platform_order_shipments')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'type' => 'DropShipment', 'user_id' => $integration->user_id])->whereIn('shipment_id', $mainArr)->delete();

                            //             /* Prepare data for insert */
                            //             foreach ($mainArr as $drop_ship_id) {
                            //                 array_push($InsertData, ['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'shipment_id' => $drop_ship_id, 'sync_status' => 'Pending', 'type' => 'DropShipment']);
                            //             }

                            //             if (!empty($InsertData)) {
                            //                 $this->mobj->makeInsert('platform_order_shipments', $InsertData);
                            //             }
                            //         }
                            //     } else {
                            //         $mainArr = array_unique($mainArr);
                            //         if (!empty($mainArr)) {
                            //             $mainArr = array_unique($mainArr);
                            //             /* Set Delete if duplicate ids found for same integration*/
                            //             DB::table('platform_order_shipments')->where([['user_id', '=', $integration->user_id], ['platform_id', '=', $this->platformId], ['user_integration_id', '=', $user_integration_id]])->whereIn('shipment_id', $mainArr)->delete();

                            //             /* Prepare data for insert */
                            //             foreach ($mainArr as $goods_id) {
                            //                 array_push($InsertData, ['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'shipment_id' => $goods_id, 'sync_status' => "Pending"]);
                            //             }

                            //             if (!empty($InsertData)) {
                            //                 $this->mobj->makeInsert('platform_order_shipments', $InsertData);
                            //             }
                            //         }
                            //     }
                            // }
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * convert string in to proper format
     */
    function convertStringToSlug( $str='', $convert="-" )
    {
        return preg_replace( '/-+/', $convert, preg_replace( '/[^a-z0-9-]+/', $convert, trim( strtolower( $str ) ) ) );
    }

    /**
     *
     */
    function checkOrCreateCustomer( $user_id, $user_integration_id, $data=[] ){

        $checkCustomerExist = PlatformCustomer::select('id', 'api_customer_id')
            ->where(
            [
                'platform_id' => $this->platformId,
                'user_integration_id' => $user_integration_id,
                'email' => $data['email'],
            ]
        )->first();

        if( $checkCustomerExist ){
            $result['customer_id'] = $checkCustomerExist->id;
            $result['contact_id'] = $checkCustomerExist->api_customer_id;
        } else {
            
            $result['customer_id'] = 0;
            $result['contact_id'] = 0;
            $platform_account = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId);
            if( $data['email'] != "" ){
                $contactDetails = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, "contacts/v1/contact/email/".$data['email']."/profile" );
                // Storage::append( 'HubSpot/CheckContact-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": contacts/v1/contact/email/".$data['email']."/profile".json_encode( $contactDetails ) );
                
                $portal_id = null;
                if( isset( $contactDetails['status'] ) && ( $contactDetails['status'] == "error" && $contactDetails['message'] == "contact does not exist" ) ){
                    $httpBuildQueryArr = [
                        "properties" => [
                            "company" => $data['company'],
                            "email" => $data['email'],
                            "firstname" => $data['first_name'],
                            "lastname" => $data['last_name'],
                            "phone" => $data['phone'],
                        ]
                    ];
                    $response = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "crm/v3/objects/contacts", json_encode( $httpBuildQueryArr ) );
                    // Storage::append( 'HubSpot/CreateContact-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": crm/v3/objects/contacts".json_encode( $httpBuildQueryArr )." ".json_encode( $response ) );
                    
                    if( $response['api_status'] == "success" ){
                        $result['contact_id'] = $response['id'];
                        $portal_id = $response['portal-id'] ?? null;
                    } else {
                        $result['contact_id'] = 0;
                    }
                } else{
                    $result['contact_id'] =  $contactDetails['vid'];
                }

                if( $result['contact_id'] >0 ){
                    $customer = PlatformCustomer::where(
                        [
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'api_customer_id' => $result['contact_id'],
                            // 'api_customer_code' => $response['portal-id']
                        ]
                    )->first();
    
                    if( !$customer ){
                        $customer = new PlatformCustomer();
    
                        $customer->user_id = $user_id;
                        $customer->platform_id = $this->platformId;
                        $customer->user_integration_id = $user_integration_id;
                        $customer->api_customer_id = $result['contact_id'];
                    }
    
                    $customer->api_customer_code = $portal_id;
                    $customer->customer_name = $data['first_name']." ".$data['last_name'];
                    $customer->first_name = $data['first_name'];
                    $customer->last_name = $data['last_name'];
                    $customer->phone = $data['phone'];
                    $customer->email = $data['email'];
                    $customer->type = "Contact";
                    $customer->save();
    
                    $result['customer_id'] = $customer->id;
                }
            }
        }

        return $result;
    }

    /**
     *
     */
    public function getOwners( $user_id=109, $user_integration_id=608 ){
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId);
        $ownerArr = $this->HubSpotApi->CheckAPIResponse( "GET", $platform_account, "owners/v2/owners" );
        dd($ownerArr);
    }

    /**
     *
     */
    public function submitOwners( $user_id=109, $user_integration_id=608 ){
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId);
        $httpBuildQueryArr = [
            "properties" => [
                "email" => "gk@mailinator.com",
                "firstname" => "gautam",
                "lastname" => "patel",
            ]
        ];
        $ownerArr = $this->HubSpotApi->CheckAPIResponse( "POST", $platform_account, "owners/v2/owners", json_encode( $httpBuildQueryArr ) );
        dd($ownerArr);
    }

    /**
     *
     */
    public function getEmails(){
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration( 608, $this->platformId);

        if( $platform_account ){

            $url = $platform_account->api_domain."crm/v3/objects/tickets/:16876924921/email";//?associations=hs_email_body";
            // $url = 'https://api.hubapi.com/crm/v3/objects/emails?limit=10&archived=false&properties=hs_email_text,hs_email_direction,hs_email_status,hs_email_headers&associations=ticket';
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,//,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->mobj->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                ],
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            echo $url."<br>";
            echo $response;die;
            // echo"<pre>";
            dd(json_decode($response));
        }
    }

    /*
     * Execute HubSpot Event Methods
     * ExecuteHubSpotEvents= method: MUTATE - event: TICKET - destination_platform_id: hubspot - user_id: 109 - user_integration_id: 597 - is_initial_sync: 0 - user_workflow_rule_id: 1162 - source_platform_id: whmcs - platform_workflow_rule_id: 176 - record_id:
     *  https://webhooks.apiworx.net/esb/hubspot/index.php?for=ticket&uid=278&env=prod
        https://webhooks.apiworx.net/esb/hubspot/index.php?for=ticket_reply&uid=278&env=prod
     */
    public function ExecuteHubSpotEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform='', $platform_workflow_rule_id='', $record_id='')
    {
        // Log::Info( "ExecuteHubSpotEvents = user_id: ".$user_id.", user_integration_id: ".$user_integration_id.", method: ".$method.", event: ".$event );//." - destination_platform_id: ".$destination_platform_id." - user_id: ".$user_id." - user_integration_id: ".$user_integration_id." - is_initial_sync: ".$is_initial_sync." - user_workflow_rule_id: ".$user_workflow_rule_id." - source_platform: ".$source_platform." - platform_workflow_rule_id: ".$platform_workflow_rule_id." - record_id: ".$record_id );
        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform);
        $response = true;
        
        if($method == 'GET' && $event == 'TICKET') {
            $response = $this->getTickets( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $is_initial_sync );
        } elseif($method == 'MUTATE' && $event == 'TICKET') {
            $response = $this->createTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id );
            $response = $this->replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id );
        } elseif($method == 'GET' && $event == 'TICKETSTATUS') {
            $response = $this->getTicketStatus( $user_id, $user_integration_id );
        } elseif($method == 'MUTATE' && $event == 'TICKETREPLY') {
            // $response = $this->replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        }
        return $response;
    }
}
