<?php
namespace App\Http\Controllers\Whmcs;

use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Http\Controllers\Whmcs\Api\WhmcsApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformAccount;
use App\Models\PlatformTicketAttachment;
use App\Models\PlatformTicket;
use App\Models\PlatformTicketReply;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class WhmcsApiController extends Controller
{
    public $mobj = '';
    public $WhmcsApi = '';
    public $ConnectionHelper = '';
    public $FieldMappingHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platformId = '';

    public static $myPlatform = 'WHMCS';

    /*
     * @Function:        <__contruct>
     * @Author:          Gautam Kakadiya
     * @Created On:      <01-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Create a new controller instance>
     * @Returns:         <  >
     *
     * URL https://helptest.apiworx.com/includes/api.php
     * Shriti : Identifier: le9VglAjWLKQtQQuJTtCdRdjnAWB9c9B, Secret: CmWnT7N9Bfs2YDzhtLmFCRH0GDyKbJXc
    */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->WhmcsApi = new WhmcsApi();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->Logger = new Logger();
        $this->WorkflowSnippet = new WorkflowSnippet();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /*
     * @Function:        <Initiate WHMCS Authentication>
     * @Author:          Gautam Kakadiya
     * @Created On:      <01-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Initiate WHMCS Authentication>
     * @Returns:         < WHMCS UI Authentication>
    */
    public function InitiateWhmcsAuth(Request $request)
    {
        $platform = 'WHMCS';
        return view("pages.apiauth.auth_whmcs", compact('platform'));
    }

    /*
     * @Function:        <Connect WHMCS>
     * @Author:          Gautam Kakadiya
     * @Created On:      <01-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Made WHMCS connection>
     * @Returns:         <WHMCS Authentication tocket code>
    */
    public function ConnectWhmCs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required',
            'user_name' => 'required',
            'user_password' => 'required',
            'end_point' => 'required|url'
        ]);

        if($this->mobj->checkHtmlTags( $request->all()) ) {
            $data['error'] = Lang::get('tags.validate');
            return response()->json( $data, 200 );
        }

        if($validator->fails()) {
            return response()->json( $validator->messages(), 200 );
        } else {
            $user_data =  Session::get('user_data');
            $user_id =  $user_data['id'];

            $account_name = trim( $request->account_name );
            $user_name = trim( $request->user_name );
            $app_secret = trim($request->user_password);
            $api_domain = trim($request->end_point);

            $authDetails = array();
            $authDetails['end_point'] = $api_domain;
            $authDetails['user_name'] = $user_name;
            $authDetails['user_password'] = $app_secret;

            $httpBuildQueryArr = [
                'action' => 'CreateOAuthCredential',
                'username' => $authDetails['user_name'],
                'password' => $authDetails['user_password'],
                'grantType' => 'authorization_code',
                'scope' => 'clientarea:sso clientarea:billing_info clientarea:announcements',
                'name' => $account_name,
                'responsetype' => 'json',
            ];
            $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );

            if ($response['result'] == 'success') {
                if (isset($response['credentialId'])) {
                    $account = PlatformAccount::where([
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $account_name
                        ])->first();

                    if ( !$account ) {
                        $account = new PlatformAccount();
                    }

                    $account->access_token = $this->mobj->encrypt_decrypt($response['clientSecret'],'encrypt');
                    $account->app_id = $this->mobj->encrypt_decrypt( $user_name, 'encrypt' );
                    $account->app_secret = $this->mobj->encrypt_decrypt( $app_secret, 'encrypt' );
                    $account->marketplace_id = $response['credentialId'];
                    $account->account_name = $account_name;
                    $account->api_domain = $api_domain;
                    $account->user_id = $user_id;
                    $account->platform_id = $this->platformId;
                    $account->expires_in = 3600;
                    $account->token_refresh_time = time();
                    $account->allow_refresh = 0;
                    $account->save();

                    $data['success'] = "Successfully Connected";
                    return response()->json( $data, 200 );
                } else {
                    $data['error'] = "Sign-in information is incorrect";
                    return response()->json( $data, 200 );
                }
            }else{
                $data['error'] = $response['message'] ?? $response['api_error'];//->api_error ?? "Authentication Error";;
                return response()->json( $data, 200 );
            }
        }
    }

    /*
     * @Function:        <getTicketStatus>
     * @Author:          Gautam Kakadiya
     * @Created On:      <22-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Get the support statuses and number of tickets in each status>
     * @Returns:         <WHMCS ticket status>
    */
    public function getTicketStatus($user_id, $user_integration_id)
    {
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
                    $authDetails = $this->getAccountAuthDetails( $platform_account );
                    $httpBuildQueryArr = [
                        'action' => 'GetSupportStatuses',
                        'username' => $authDetails['user_name'],
                        'password' => $authDetails['user_password'],
                        'responsetype' => 'json',
                    ];

                    $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
                    if( $response['result'] == 'success' && COUNT( $response['statuses'] ) > 0 )
                    {
                        foreach($response['statuses']['status'] as $status)
                        {
                            $ticketStatus = [
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_object_id' => $ticket_object->id,
                                'api_id' => $this->convertStringToSlug( $status['title'] ),
                                'name' => $status['title'],
                                'api_code' => Strtoupper ( $this->convertStringToSlug( $status['title'] ) ),
                                'description' => "Name: ".$status['title'].", Total Tickets: ".$status['count'].", Color: ".$status['color'],
                                'status' => 1
                            ];

                            $platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data',
                                [
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_object_id' => $ticket_object->id,
                                    'api_id' => $this->convertStringToSlug( $status['title'] ),
                                    'api_code' => Strtoupper ( $this->convertStringToSlug( $status['title'] ) ),
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
            Log::error($user_integration_id.' - WhmcsApiController - getTicketStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    /*
     * @Function:        <getTicketDepartment>
     * @Author:          Gautam Kakadiya
     * @Created On:      <22-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Get the support departments and associated ticket counts>
     * @Returns:         <WHMCS Department>
    */
    public function getTicketDepartment($user_id, $user_integration_id)
    {
        $return_data = true;
        try
        {
            $ticket_department = $this->mobj->getFirstResultByConditions('platform_objects', ['name'=>'ticket_department'], ['id']);
            if($ticket_department)
            {
                $this->mobj->makeUpdate('platform_object_data', ['status'=>0], [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ticket_department->id,
                    'status' => 1
                ]);

                $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
                if($platform_account)
                {
                    $authDetails = $this->getAccountAuthDetails( $platform_account );
                    $httpBuildQueryArr = [
                        'action' => 'GetSupportDepartments',
                        'username' => $authDetails['user_name'],
                        'password' => $authDetails['user_password'],
                        'responsetype' => 'json',
                    ];

                    $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
                    
                    if( $response['result'] == 'success' && isset( $response['departments'] ) && COUNT( $response['departments'] ) > 0 )
                    {
                        if( true ){
                            foreach($response['departments']['department'] as $dept)
                            {
                                $ticketStatus = [
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_object_id' => $ticket_department->id,
                                    'api_id' => $dept['id'],
                                    'name' => $dept['name'],
                                    'api_code' => Strtoupper ( $this->convertStringToSlug( $dept['name'] ) ),
                                    'description' => "ID: ".$dept['id'].", Name: ".$dept['name'],
                                    'status' => 1
                                ];

                                $platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data',
                                    [
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_object_id' => $ticket_department->id,
                                        'api_id' => $dept['id'],
                                        'api_code' => Strtoupper ( $this->convertStringToSlug( $dept['name'] ) ),
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
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id.' - WhmcsApiController - getTicketStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    /*
     * @Function:        <getTicketDetails>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain a specific ticket>
     * @Returns:         <   >
    */
    public function getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $isReturnResponse=false ){
        
        $return_response = true;
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId );

        if( $platform_account ){
            try{
                $sync_object_id = $this->ConnectionHelper->getObjectId('platform_ticket');

                $authDetails = $this->getAccountAuthDetails( $platform_account );
                $httpBuildQueryArr = [
                    'username' => $authDetails['user_name'],
                    'password' => $authDetails['user_password'],
                    'action' => 'GetTicket',
                    'ticketid' => $ticket_id,
                    'responsetype' => 'json',
                ];

                $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
               
                if ($response['result'] == 'success') {
                    if( $isReturnResponse ){
                        return $response;
                    } else {
                        $this->storeTicketDetails( $response, $user_id, $user_integration_id );
                    }
                }  else {
                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', 0, $response['api_error'] );
                }
            }  catch ( Exception $e) {
                Log::error( $user_integration_id." <-- ExecuteWHMCSEvents getTicketDetails --> ".$e->getMessage());
                $return_response = $e->getMessage();
            }
        }  else {
            $return_response = false;
        }

        return $return_response;
    }

    /*
     * @Function:        <getTickets>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain tickets matching the passed criteria>
     * @Returns:         <   >
    */
    public function getTickets( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $is_initial_sync=0, $platform_workflow_rule_id ){
        $return_response = true;
        $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
        $offset = 0;
        $pagesize = 100;
        $limit = [];

        if( $platform_account ){

            try{
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', [
                    'user_id' => $user_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'whmcs_get_ticket_limit'
                ],
                ['url', 'id']);

                if ($limit && $limit->url ) {
                    $offset = $limit->url;
                }


                $authDetails = $this->getAccountAuthDetails( $platform_account );

                $httpBuildQueryArr = [
                    'action' => 'GetTickets',
                    'username' => $authDetails['user_name'],
                    'password' => $authDetails['user_password'],
                    'responsetype' => 'json',
                    'limitstart' => $offset, 
                ];

                $ticketFilter = $this->ConnectionHelper->getObjectId('ticket_status');//ticket status filter
                $getAcceptTicketStatus = $this->FieldMappingHelper->getMappedApiIdByObjectId($user_integration_id, $ticketFilter );
                if( $getAcceptTicketStatus != "all" && $getAcceptTicketStatus != null ){
                    $httpBuildQueryArr['status'] = $getAcceptTicketStatus;// optional: filter by ticket status
                }

                //get Sync Start Date
                $syncStartDate = 0;
                $ticketStartSyncDateFilter = $this->mobj->getFirstResultByConditions('user_workflow_rule', [
                    'user_integration_id' => $user_integration_id,
                    // 'status' => 1,
                    'platform_workflow_rule_id' => $platform_workflow_rule_id,
                    // 'id' => $user_workflow_rule_id,
                ], [
                    'sync_start_date'
                ]);

                // Storage::append( 'WHMCS/'.$user_integration_id.'/getTickets/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Start Date: ".json_encode( $ticketStartSyncDateFilter ) );

                if( $ticketStartSyncDateFilter ){
                    $syncStartDate = strtotime( $ticketStartSyncDateFilter->sync_start_date );// optional: filter by ticket date filter
                }

                $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
                // Storage::append( 'WHMCS/'.$user_integration_id.'/getTickets/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $httpBuildQueryArr )." : ".json_encode( $response ) );
                if ( $response['result'] === 'success') {
                    if( $response['numreturned'] > 0 ){
                        //pluck ticket id / status
                        $ticketIdArr = [];
                        foreach( $response['tickets']['ticket'] as $ar ){
                            $ticketIdArr [] = $ar['ticketid'];
                        }

                        $totalTicketLines = PlatformTicket::where([
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'is_deleted' => 0
                        ])
                        ->where( 'ticket_status', '!=', 'Closed' )
                        ->whereIn( 'api_ticket_id', $ticketIdArr)
                        ->pluck( 'api_ticket_id' )//'ticket_status', 'api_ticket_id'
                        ->toArray();

                        foreach( $response['tickets']['ticket'] as $ar ){
                            $ticketDate = strtotime( $ar['date'] );
                            if( $syncStartDate <= $ticketDate && $ticketStartSyncDateFilter && ( in_array( $ar['ticketid'], $totalTicketLines ) || $ar['status'] != 'Closed' ) ){//status == Closed
                                $this->getTicketDetails( $user_id, $ar['ticketid'], $user_integration_id, $user_workflow_rule_id, $source_platform_id );
                            }
                        }

                        $offset = ( $response['numreturned'] + $offset );

                        if( $offset >= $response['totalresults'] ){
                            $offset = 0;
                        }
                    } else {
                        $offset = 0;
                    }

                    if ( $limit ) {
                        $this->mobj->makeUpdate('platform_urls', ['url' => $offset], ['id' => $limit->id]);
                    } else {
                        $this->mobj->makeInsert('platform_urls', [
                            'user_id' => $user_id, 
                            'user_integration_id' => $user_integration_id, 
                            'platform_id' => $this->platformId, 
                            'url' => $offset, 
                            'url_name' => 'whmcs_get_ticket_limit'
                        ]);
                    }
                }

            } catch ( Exception $e) {
                Log::error( $user_integration_id."-- ExecuteWhmcsEvents getTickets -->".$e->getMessage());
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
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Obtain ticket detail the passed criteria>
     * @Returns:         <   >
    */
    public function storeTicketDetails( $ticketDetails, $user_id, $user_integration_id  ){

        $return_response = true;
        try{

            $ticket = PlatformTicket::where( [
                // "user_id" => $user_id,
                "api_ticket_id" => $ticketDetails['ticketid'],
                "platform_id" => $this->platformId,
                "user_integration_id" => $user_integration_id
            ] )->first();

            $createOrUpdate = 1;

            if( !$ticket ){
                $ticket = new PlatformTicket();
                $ticket->name = $ticketDetails['name'];
                $ticket->email = $ticketDetails['email'];
                $ticket->sync_status = PlatformStatus::READY;
            } else if ( strtotime( $ticketDetails['lastreply'] ) == strtotime( $ticket->ticket_update_date ) ){
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
            $ticket->subject = $ticketDetails['subject'];
            $ticket->ticket_status = $ticketDetails['status'];
            $ticket->deptid = $ticketDetails['deptid'];
            $ticket->deptname = $ticketDetails['deptname'];
            $ticket->contactid = $ticketDetails['contactid'];
            $ticket->flag = $ticketDetails['flag'];
            $ticket->priority = $ticketDetails['priority'];
            $ticket->requester_type = $ticketDetails['requestor_type'];
            $ticket->api_ticket_id = $ticketDetails['ticketid'];
            $ticket->ticket_number = $ticketDetails['tid'];
            $ticket->ticket_date = $ticketDetails['date'];
            $ticket->ticket_update_date = $ticketDetails['lastreply'];
            $ticket->message = $ticketDetails['replies']['reply'][0]['message'] ?? '';

            if( env('APP_ENV') != 'prod' ){
                $ticket->json_object = json_encode( $ticketDetails );
            }

            $ticket->save();

            //Ticket Reply section
            if( isset( $ticketDetails['replies'] ) && isset( $ticketDetails['replies']['reply'] ) && COUNT( $ticketDetails['replies']['reply'] ) > 0 ){
                
                foreach( $ticketDetails['replies']['reply'] as $ar ){

                    $where = [
                        'platform_ticket_id' => $ticket->id,
                        'ticket_id' => $ticketDetails['ticketid'],
                        'reply_id' => $ar['replyid']
                    ];
                    
                    $reply = PlatformTicketReply::where( $where )->first();

                    $createOrUpdate = 1;
                    $sync_status = PlatformStatus::READY;
                    if( !$reply ){
                        $reply = new PlatformTicketReply();

                        $reply->platform_ticket_id = $ticket->id;
                        $reply->ticket_id = $ticketDetails['ticketid'];
                        $reply->reply_id = $ar['replyid'];
                        $reply->name = $ar['name'];
                        $reply->email = $ar['email'];
                        $reply->requestor_type = $ar['requestor_type'];
                        $reply->message = $ar['message'];

                        $reply->date = $ar['date'];
                        $reply->sync_status = $sync_status;
                        $reply->save();

                        //save attachment here
                        if( count( $ar['attachments'] ) >0 ){
                            foreach( $ar['attachments'] as $at ){
                                if( $at['filename'] != "" ){
                                    $at['support_ticket_id'] = $ticketDetails['ticketid'];
                                    $at['replyid'] = $ar['replyid'];
                                    $at['relatedid'] = $ar['replyid'];
                                    $type = "reply";
                                    if( $ar['replyid'] == 0 ){
                                        $at['relatedid'] = $ticketDetails['ticketid'];
                                        $type = "ticket";
                                    }
                                    $this->storeTicketAttachment( $user_id, $ticket->id, $at, $user_integration_id, $type );
                                }
                            }
                        }
                    }
                }
            }

            //Ticket Note section
            if( isset( $ticketDetails['notes'] ) && isset( $ticketDetails['notes']['note'] ) && COUNT( $ticketDetails['notes']['note'] ) > 0 ){
                $checkArrayContent = [
                    'Tasks:',
                    'Emails:',
                    'Meetings:',
                    'Notes:',
                    'Calls:',
                ];
                foreach( $ticketDetails['notes']['note'] as $ar ){

                    $process = true;
                    foreach ($checkArrayContent as $url) {
                        if ( strpos( $ar['message'], $url ) !== FALSE) {
                            $process = false;
                        }
                    }

                    if ( $process ) {
                        $where = [
                            'platform_ticket_id' => $ticket->id,
                            'ticket_id' => $ticketDetails['ticketid'],
                            'reply_id' => $ar['noteid']
                        ];
                        
                        $reply = PlatformTicketReply::where( $where )->first();

                        $createOrUpdate = 1;
                        $sync_status = PlatformStatus::READY;
                        if( !$reply ){
                            $reply = new PlatformTicketReply();

                            $reply->platform_ticket_id = $ticket->id;
                            $reply->ticket_id = $ticketDetails['ticketid'];
                            $reply->reply_id = $ar['noteid'];
                            $reply->name = '';
                            $reply->email = '';
                            $reply->requestor_type = '';
                            $reply->message = $ar['message'];
                            $reply->date = $ar['date'];
                            $reply->sync_status = $sync_status;
                            $reply->save();

                            //save attachment here
                            if( count( $ar['attachments'] ) >0 ){
                                foreach( $ar['attachments'] as $at ){
                                    if( $at['filename'] != "" ){
                                        $at['support_ticket_id'] = $ticketDetails['ticketid'];
                                        $at['replyid'] = $ar['noteid'];
                                        $at['relatedid'] = $ar['noteid'];
                                        $this->storeTicketAttachment( $user_id, $ticket->id, $at, $user_integration_id, 'note' );
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if( false && count( $ticketDetails['attachments'] ) >0 ){//save attachment here
                foreach( $ticketDetails['attachments'] as $at ){
                    if( $at['filename'] != "" ){
                        $at['support_ticket_id'] = $ticketDetails['ticketid'];
                        $at['replyid'] = 0;
                        $at['relatedid'] = $ticketDetails['ticketid'];
                        $this->storeTicketAttachment( $user_id, $ticket->id, $at, $user_integration_id, 'ticket' );
                    }
                }
            }
        } catch (Exception $e) {
            Log::error( $user_integration_id."<-- ExecuteWHMCSEvents storeTicketDetails -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * @Function:        <storeTicketAttachment>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <
     *                      There's no magic, you should download external image using copy() function, then send it to user in the response:
     *                   >
     * @Returns:         <   >
    */
    public function storeTicketAttachment( $user_id, $ticket_id, $data, $user_integration_id, $type = "ticket" ){
        $return_response = true;
        try{
            $reply_id = $data['replyid'] ?? 0;
            $where['platform_ticket_id'] = $ticket_id;
            $where['ticket_id'] = $data['support_ticket_id'];
            
            $index = $data['index'];
            $where['index'] = $index;
            $where['reply_id'] = $reply_id;
            
            $attachment = PlatformTicketAttachment::where( $where )->first();
            if( !$attachment ){
                $attachment = new PlatformTicketAttachment();

                //download external image using copy() function
                $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

                $authDetails = $this->getAccountAuthDetails( $platform_account );
                $httpBuildQueryArr = [
                    'action' => 'GetTicketAttachment',
                    'username' => $authDetails['user_name'],
                    'password' => $authDetails['user_password'],
                    'relatedid' => $data['relatedid'],
                    'type' => $type,
                    'index' => $index,
                    'responsetype' => 'json',
                ];

                $newFileName = $data['support_ticket_id']."-".$data['filename'];
                $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
                
                $filePath = storage_path( "app/ticket/".$newFileName );
                file_put_contents( $filePath, base64_decode( $response['data'] ) );

                $attachment->platform_ticket_id = $ticket_id;
                $attachment->ticket_id = $data['support_ticket_id'];
                $attachment->reply_id = $reply_id;
                $attachment->filename = $newFileName;
                $attachment->file_path = $filePath;
                $attachment->index = $index;
                $attachment->status = 1;
                $attachment->save();
            }
        } catch ( Exception $e) {
            Log::error($user_integration_id."<-- ExecuteWHMCSEvents storeTicketAttachment -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * @Function:        <replyTicket>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     < Add a reply to a ticket by Ticket ID. >
     * @Returns:         <   >
    */
    public function replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id=0, $source_platform_id=47 ){
        $return_response = true;

        try
        {
            $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if( $platform_account ){
                $limit = 25;
                $offset = 0;

                $platformTicketArr = PlatformTicket::
                    where([
                        // 'platform_tickets.user_id' => $user_id,
                        'platform_tickets.platform_id' => $source_platform_id,
                        'platform_tickets.user_integration_id' => $user_integration_id,
                        'platform_tickets.sync_reply_status' => PlatformStatus::READY,
                    ])
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                if( count($platformTicketArr) > 0){
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
                            $sourceTicketArr = PlatformTicket::
                                select( 'api_ticket_id', 'id' )
                                ->where( "id", $platform_ticket['linked_id'] )
                                ->first();

                            $ticket_status = null;

                            /*----------------Start to find order status----------------*/
                            $ticket_status_name = $this->mobj->getFirstResultByConditions('platform_object_data', [ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id, 'platform_object_id'=>$ticket_object->id, 'name'=>$platform_ticket->ticket_status, 'status'=>1], ['api_id']);
                            if($ticket_status_name) {
                                $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $ticket_status_name->api_id, "single", $SourceOrDestination);
                                if($map_ticket_status) {
                                    $ticket_status = $map_ticket_status->api_id;
                                }
                            } else {
                                $map_ticket_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_ticket_status", ['api_id'], 'regular', $platform_ticket->ticket_status, "single", $SourceOrDestination);
                                if($map_ticket_status) {
                                    $ticket_status = $map_ticket_status->api_id;
                                }
                            }

                            $updatePlatformTicketStatus = PlatformTicket::find( $platform_ticket['id'] );
                            if( isset( $sourceTicketArr->api_ticket_id) ){
                                $ticketid = $sourceTicketArr->api_ticket_id;
                                $authDetails = $this->getAccountAuthDetails( $platform_account );
                                $httpBuildQueryArr = [
                                    'action' => 'UpdateTicket',
                                    'username' => $authDetails['user_name'],
                                    'password' => $authDetails['user_password'],
                                    'ticketid' => $ticketid,
                                    'subject' => $platform_ticket['subject'],
                                    'clientid' => 1,
                                    'message' => $platform_ticket['message'],
                                    'status' => $ticket_status,
                                    'responsetype' => 'json',
                                ];

                                $response = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );
                                if ( isset( $response['result'] ) && $response['result'] == 'success') {
                                    //get ticket replay & update it
                                    $getPlatformTicketReplyArr = PlatformTicketReply::where( [
                                        'platform_ticket_id' => $platform_ticket['id'],
                                        'ticket_id' => $platform_ticket['api_ticket_id'],
                                        'sync_status' => PlatformStatus::READY,
                                        'linked_id' => 0,
                                    ])
                                    ->where( 'reply_id', '!=', 0 )
                                    ->orderBy( 'reply_id' )
                                    ->get();

                                    foreach( $getPlatformTicketReplyArr as $platform_ticket_reply ){

                                        if( $platform_ticket_reply['message'] != "" ){
                                            $ticketAttachmentArr = [];
                                            $getPlatformTicketAttachmentArr = PlatformTicketAttachment::
                                            select( 'filename', 'file_path' )
                                            ->where( [
                                                'ticket_id' => $platform_ticket_reply->ticket_id,
                                                'platform_ticket_id' => $platform_ticket_reply->platform_ticket_id,
                                                'reply_id' => $platform_ticket_reply->reply_id
                                            ])
                                            ->where( 'filename', "!=", "" )
                                            ->get();

                                            foreach( $getPlatformTicketAttachmentArr as $platform_ticket_attachment ){
                                                $ticketAttachmentArr[] = $this->CreateAttachment( time(), url( $platform_ticket_attachment->file_path ), $platform_ticket_attachment->filename );
                                            }

                                            $httpBuildQueryArr = [
                                                'action' => "AddTicketNote",
                                                'username' => $authDetails['user_name'],
                                                'password' => $authDetails['user_password'],
                                                'ticketid' => $ticketid,
                                                'replyid' => $platform_ticket_reply->reply_id,
                                                'clientid' => 1,
                                                'message' => strip_tags(htmlspecialchars_decode( "<b>".ucfirst( $platform_ticket_reply->type ).": </b>".$platform_ticket_reply['message'] ) ),
                                                'markdown' => true,
                                                'attachments' => base64_encode(json_encode($ticketAttachmentArr)),
                                                'responsetype' => 'json',
                                            ];

                                            $replyResponse = $this->WhmcsApi->CheckAPIResponse( $authDetails, $httpBuildQueryArr );

                                            $updatePlatformTicketReplyStatus = PlatformTicketReply::find( $platform_ticket_reply->id );
                                            $linked_id = 0;
                                            $updatePlatformTicketReplyStatus->sync_status = PlatformStatus::SYNCED;
                                            if( $replyResponse['api_status'] != "success" ){
                                                $updatePlatformTicketReplyStatus->sync_status = PlatformStatus::FAILED;
                                                $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, $replyResponse['api_error'] );
                                            } else {
                                                $ticketResponse = $this->getTicketDetails( $user_id, $ticketid, $user_integration_id, $user_workflow_rule_id, $source_platform_id, true );
                                                if( isset( $ticketResponse['replies']['reply'] ) ){
                                                    $ticketReplyResponseArr = array_reverse( $ticketResponse['replies']['reply'] );

                                                    $ticketReply = new PlatformTicketReply();
                                                    $ticketReply->ticket_id = $sourceTicketArr->api_ticket_id;
                                                    $ticketReply->platform_ticket_id = $sourceTicketArr->id;
                                                    $ticketReply->reply_id = $ticketReplyResponseArr[0]['replyid'];
                                                    $ticketReply->name = $ticketReplyResponseArr[0]['name'];
                                                    $ticketReply->email = $ticketReplyResponseArr[0]['email'];
                                                    $ticketReply->requestor_type = $ticketReplyResponseArr[0]['requestor_type'];
                                                    $ticketReply->date = $ticketReplyResponseArr[0]['date'];
                                                    $ticketReply->message = $ticketReplyResponseArr[0]['message'];
                                                    $ticketReply->linked_id = $platform_ticket_reply->id;
                                                    $ticketReply->sync_status = PlatformStatus::SYNCED;
                                                    $ticketReply->save();
                                                    $linked_id = $ticketReply->id;

                                                    //save attachment here
                                                    if( count( $ticketReplyResponseArr[0]['attachments'] ) >0 ){
                                                        foreach( $ticketReplyResponseArr[0]['attachments'] as $at ){
                                                            if( $at['filename'] != "" ){
                                                                $at['support_ticket_id'] = $ticketResponse['ticketid'];
                                                                $at['replyid'] = $ticketReplyResponseArr[0]['replyid'];
                                                                $at['relatedid'] = $ticketReplyResponseArr[0]['replyid'];
                                                                $this->storeTicketAttachment( $user_id, $platform_ticket['api_ticket_id'], $at, $user_integration_id, 'reply' );
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            $updatePlatformTicketReplyStatus->linked_id = $linked_id;
                                            $updatePlatformTicketReplyStatus->save();
                                        }
                                    }

                                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $platform_ticket->id, null );
                                    $updatePlatformTicketStatus->sync_reply_status = PlatformStatus::SYNCED;
                                    $updatePlatformTicketStatus->sync_status = PlatformStatus::SYNCED;
                                    $updatePlatformTicketStatus->save();
                                } else {
                                    $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, $response['api_error'] );
                                    $updatePlatformTicketStatus->sync_reply_status = PlatformStatus::FAILED;
                                    $updatePlatformTicketStatus->save();
                                }
                            } else {
                                $this->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $platform_ticket->id, "Not found proper WHMCS ticket response" );
                                $updatePlatformTicketStatus->sync_reply_status = PlatformStatus::FAILED;
                                $updatePlatformTicketStatus->save();
                            }
                        }  else {
                            $return_response = false;
                        }
                    }
                }
            } else {
                $return_response = false;
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteWHMCSEvents replyTicket -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * @Function:        <createTicket>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     < Open a new ticket >
     * @Returns:         <   >
    */
    public function createTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id ){
        return $return_response = true;
    }

    /*
     * @Function:        <createTicket>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     < Optional base64 json encoded array of file attachments. Can be the direct output of a multipart-form-data form
     *                          submission ($_FILES superglobal in PHP) or an array of arrays consisting of both a filename and data keys
                            (see example below). >
     * @Returns:         <   >
     */
    public function CreateAttachment( $time, $filepath="", $filename="" ){
        
        if( $filepath != "" ){
            $explodeExtension = explode( ".", $filepath );
            $fileSource = file_get_contents( $filepath );
            return [
                        'name' => ( $filename != "" ) ? $filename : 'attachment-'.$time.'.'.end( $explodeExtension ),
                        'data' => base64_encode( $fileSource )//Encode the image string data into base64
                    ]
                ;
        } else {
            return '';
        }
    }

    /**
     * convert string in to proper format
     */
    function convertStringToSlug( $str='', $convert="-" )
    {
        return preg_replace( '/-+/', $convert, preg_replace( '/[^a-z0-9-]+/', $convert, trim( strtolower( $str ) ) ) );
    }

    /**
     * get Authentication details
     */
    function getAccountAuthDetails( $platform_account )
    {
        $authDetails['end_point'] = $platform_account->api_domain;
        $authDetails['user_name'] = $this->mobj->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
        $authDetails['user_password'] = $this->mobj->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );

        return $authDetails;
    }

    /**
     * Webhook get data
     */
    function getWebhookData( Request $request, $user_integration_id ){
        
        $response = $request->all();
        $user_id = $response['user_id'];
        $user_workflow_rule_id = $response['user_workflow_rule_id'];
        $source_platform_id = $this->platformId;
        $ticket_id = $response['ticketid'];
        $hook = $response['hook'];

        Storage::append( 'WHMCS/'.$user_integration_id.'/getWebhookData/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $request->all() ) );

        $ticketObj = PlatformTicket::where( [
            'api_ticket_id' => $ticket_id,
            'platform_id' => $source_platform_id,
        ] )
        ->select( 'subject', 'ticket_status', 'sync_status', 'ticket_update_date', 'priority', 'is_deleted' )
        ->first();

        Storage::append( 'WHMCS/'.$user_integration_id.'/getWebhookData/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$hook );

        if( $hook == "TicketPriorityChange" ){
            $ticketObj->priority = $response['priority'];
            $ticketObj->sync_status = PlatformStatus::READY;
            $ticketObj->ticket_update_date = date( 'Y-m-d H:i:s' );
            $ticketObj->save();
        } else if( $hook == "TicketClose" ){
            $ticketObj->ticket_status = "Closed";
            $ticketObj->sync_status = PlatformStatus::READY;
            $ticketObj->ticket_update_date = date( 'Y-m-d H:i:s' );
            $ticketObj->save();
        } else if( $hook == "TicketSubjectChange" ){
            $ticketObj->subject = $response['subject'];
            $ticketObj->sync_status = PlatformStatus::READY;
            $ticketObj->ticket_update_date = date( 'Y-m-d H:i:s' );
            $ticketObj->save();
        } else if( $hook == "TicketStatusChange" ){
            $ticketObj->ticket_status = $response['status'];
            $ticketObj->sync_status = PlatformStatus::READY;
            $ticketObj->ticket_update_date = date( 'Y-m-d H:i:s' );
            $ticketObj->save();
        } else if( $hook == "TicketOpenAdmin" ){
            $this->getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        } else if( $hook == "TicketAdminReply" ){
            $this->getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        } else if( $hook == "TicketDelete" ){
            $ticketObj->is_deleted = 1;
            $ticketObj->save();
        } else if( $hook == "TicketUserReply" ){
            $this->getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        } else if( $hook == "TicketOpen" ){
            $this->getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        } else if( $hook == "TicketAddNote" ){
            $this->getTicketDetails( $user_id, $ticket_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        }
    }

    /*
     * @Function:        <ExecuteWhmcsEvents>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     < Execute Whmcs Event Methods >
     * ExecuteWhmcsEvents= method: MUTATE - event: TICKET - destination_platform_id: whmcs - user_id: 109 - user_integration_id: 597 - is_initial_sync: 0 - user_workflow_rule_id: 1163 - source_platform_id: hubspot - platform_workflow_rule_id: 181 - record_id:
     * @Returns:         <   >
     *  https://webhooks.apiworx.net/esb/whmcs/index.php?for=ticket&uid=278&env=prod
        https://webhooks.apiworx.net/esb/whmcs/index.php?for=ticket_reply&uid=278&env=prod
    */
    public function ExecuteWhmcsEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform='', $platform_workflow_rule_id='', $record_id='')
    {
        // $log = "method: ".$method.", event: ".$event.", destination_platform_id: ".$destination_platform_id.", user_id: ".$user_id.", user_integration_id: ".$user_integration_id.", is_initial_sync: ".$is_initial_sync.", user_workflow_rule_id: ".$user_workflow_rule_id.", source_platform: ".$source_platform.", platform_workflow_rule_id: ".$platform_workflow_rule_id;
        // Storage::append( 'WHMCS/'.$user_integration_id.'/ExecuteWhmcsEvents/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$log );

        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform);
        $response = true;
        
        if($method == 'GET' && $event == 'TICKET') {
            $response = $this->getTickets( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $is_initial_sync, $platform_workflow_rule_id );
        } elseif($method == 'MUTATE' && $event == 'TICKET') {
            // $response = $this->createTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
            $response = $this->replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        } elseif($method == 'GET' && $event == 'TICKETSTATUS') {
            $response = $this->getTicketStatus( $user_id, $user_integration_id );
        } elseif($method == 'GET' && $event == 'DEPARTMENT') {
            $response = $this->getTicketDepartment( $user_id, $user_integration_id );
        } elseif($method == 'MUTATE' && $event == 'TICKETREPLY') {
            $this->replyTicket( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id );
        }
        return $response;
    }
}
