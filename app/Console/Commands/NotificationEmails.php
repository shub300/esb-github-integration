<?php
	namespace App\Console\Commands;
	
	use Illuminate\Console\Command;
	use App\User;
    use App\Models\NotificationEmail;
    use App\Models\UserIntegration;
	use DB;
	use App\Helper\MainModel;
	use Config;
	use Mail;
	use App\Mail\DefaultMailable;


	class NotificationEmails extends Command
	{
		public static $defaultToName = 'Team';
		/**
		* The name and signature of the console command.
		*
		* @var string
		*/
		protected $signature = 'command:NotificationEmails';
		
		/**
			* The console command description.
			*
			* @var string
		*/
		protected $description = 'Send failed Sync record notification email to user.';
		
		/**
		* Create a new command instance.
		*
		* @return void
		*/

		public $defaultMailgunApiKey = '';
	    public $defaultMailgunApiUrl = '';

		public function __construct()
		{
			parent::__construct();
			$this->defaultMailgunApiKey = env( 'DEFAULT_MAILGUN_API_KEY' );
			$this->defaultMailgunApiUrl = env( 'DEFAULT_MAILGUN_API_URL' );
		}
		
		/**
			* Execute the console command.
			*
			* @return int
		*/
		public function handle()
		{
			$mobj = new MainModel();

			$from = env('MAIL_REGISTRATION_FROM_ADDRESS');
			$from_name = env('MAIL_FROM_NAME');

			//store all organizations data in array by single query to reduce database call
			$organization_data_arr = [];
			$org_data = DB::table('es_organizations')->select('organization_id','logo_url','name')
			->where('status',1)->get();
			for($i=0;$i<count($org_data);$i++)
			{	
				$organization_data_arr[$org_data[$i]->organization_id] = [
					'name'=>$org_data[$i]->name,
					'logo_url'=>$org_data[$i]->logo_url
				];
			}
		
			//collect template data with 0 status & organiations
			$template_data_arr = [];
			$templates = DB::table('es_email_template')->select('organization_id','mail_subject', 'mail_body')
			->where(['mail_type' => 'sync_fail_notification', 'active' => 1])->groupBy('organization_id')->get();
			for($j=0;$j<count($templates);$j++)
			{	
				$template_data_arr[$templates[$j]->organization_id] = [
				'mail_subject'=>$templates[$j]->mail_subject,
				'mail_body'=>$templates[$j]->mail_body
				];
			}

			//get active users to send sync failed emails
			$ActiveUserList = DB::table('user_integrations as ui')
			->join('users','ui.user_id','users.id')
			->leftJoin('es_notification_email as esnm','ui.user_id','esnm.user_id')
			->select('ui.user_id','users.organization_id','users.name','users.email','esnm.emails')
			->where('ui.workflow_status','active')
			->where('users.status',1)
			->groupBy('ui.user_id')->get();

			if( count($ActiveUserList) > 0)
			{
				foreach($ActiveUserList as $userList){
				
					$userId = $userList->user_id;
					$organization_id = $userList->organization_id;
					$userName = $userList->name;
					$userEmail = $userList->email;

					//get userWorkflow list
					// $user_integ_wf_data = DB::table('user_integrations')
					// ->join('platform_integrations','user_integrations.platform_integration_id','platform_integrations.id')
					// ->join('user_workflow_rule', 'user_integrations.id', '=', 'user_workflow_rule.user_integration_id')
					// ->join('platform_lookup as pl1','platform_integrations.source_platform_id','pl1.id')
					// ->join('platform_lookup as pl2','platform_integrations.destination_platform_id','pl2.id')
					// ->join('platform_workflow_rule as pwfr','pwfr.id','user_workflow_rule.platform_workflow_rule_id')
					// ->join('platform_events as pe','pe.id','pwfr.destination_event_id')
					// ->select('user_integrations.id as userIntegId', 'user_integrations.flow_name','platform_integrations.description','user_workflow_rule.id as userWFR','pl1.platform_name as sourcePlt','pl2.platform_name as destPlt','pe.event_name')
					// ->where('user_integrations.workflow_status', 'active')
					// ->where('user_integrations.user_id',$userId)
					// ->where('user_workflow_rule.status', 1)
					// ->orderBy('user_integrations.id')
					// ->get();


					//get userWorkflow list
					$user_integ_wf_data = DB::table('user_integrations')
					->join('platform_integrations','user_integrations.platform_integration_id','platform_integrations.id')
					->join('user_workflow_rule', 'user_integrations.id', '=', 'user_workflow_rule.user_integration_id')
					->join('platform_workflow_rule as pwfr','pwfr.id','user_workflow_rule.platform_workflow_rule_id')

					//join platform_events for destination
					->join('platform_events as pe','pe.id','pwfr.destination_event_id')
					//join platform_events for source also
					->join('platform_events as pe2','pe2.id','pwfr.source_event_id')

					->join('platform_lookup as pl1','pe2.platform_id','pl1.id')
					->join('platform_lookup as pl2','pe.platform_id','pl2.id')
				
					->select('user_integrations.id as userIntegId', 'user_integrations.flow_name','platform_integrations.description','user_workflow_rule.id as userWFR','pl1.platform_name as sourcePlt','pl2.platform_name as destPlt','pe.event_name')

					->where('user_integrations.workflow_status', 'active')
					->where('user_integrations.user_id',$userId)
					->where('user_workflow_rule.status', 1)
					->orderBy('user_integrations.id')
					->get();


					$checkSyncFailed = false;
					$i=0;
					$emailMessage = "";
					$emailMessage .='<table style="border: 1px solid black;border-collapse: collapse;width:100%;text-align:center;">
					<thead>
					<tr style="border: 1px solid black;border-collapse: collapse;">
					<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">#</th>
					<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Integration</th>
					<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Flow</th>
					<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Event</th>
					<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Failed Records</th>
					</tr>
					</thead>
					<tbody>';

					//userWFR in loop to get sync_log data
					if( count($user_integ_wf_data) > 0 )
					{
						foreach($user_integ_wf_data as $user_integration)
						{
							$userIntegId = $user_integration->userIntegId;
							$userIntegName = strip_tags($user_integration->flow_name);
							// $integrationName = strip_tags($user_integration->description);
							$userWFR = $user_integration->userWFR;
							$sourcePlt = $user_integration->sourcePlt;
							$destPlt = $user_integration->destPlt;
							$flowName = $sourcePlt .' -> '. $destPlt;
							$event_name = $user_integration->event_name;

							//get yesterday sync failed record
							$sync_logs = DB::table('sync_logs')->selectRaw('count(sync_logs.id) as log_count')
							->where('sync_logs.user_workflow_rule_id', $userWFR)
							->where('sync_logs.user_id',$userId)
							->where('sync_logs.sync_status', 'failed')
							->whereDate('sync_logs.updated_at', date('Y-m-d', strtotime('yesterday')))
							->groupBy('sync_logs.user_workflow_rule_id')
							->get();

							//If Failed Record Found then process for error log email
							if( count($sync_logs) > 0 ){
								$checkSyncFailed = true;
								foreach($sync_logs as $sync_log)
								{
									$i++;
									$emailMessage .='<tr style="border: 1px solid black;border-collapse: collapse;">
									<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$i.'</td>
									<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$userIntegName.'</td>
									<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$flowName.'</td>
									<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$event_name.'</td>
									<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$sync_log->log_count.'</td>
									</tr>';
								}
							} 	 
								
						}
					}
						

					$emailMessage .='</tbody></table>';

					//if sync Failed Record Found for current User
					if($checkSyncFailed){

						//get notification email IDs
						if($userList->emails)
						{
							$emailIds = preg_replace('/\s+/', '', trim($userList->emails));
						} else {
							$emailIds = $userList->email;
						}

						//get organization details
						$organization_name = "";
						$logo = "";
						if (array_key_exists($organization_id,$organization_data_arr)){
							$organization_name = $organization_data_arr[$organization_id]['name'];
							$public_path = $organization_data_arr[$organization_id]['logo_url'];
							if($public_path) {
								$logo_src = env('CONTENT_SERVER_PATH') . $public_path;
								$logo = '<img src="' . $logo_src . '" alt="'.$organization_name.'" style="margin-left:30%;width:30%;"><br>';
							} else {
								$logo = '<h4 style="text-align:center">'.$organization_name.'<h4>';
							}
							
						} 

	
						//get template data
						if ( array_key_exists($organization_id,$template_data_arr) ){

							$raw_mail_subject = $template_data_arr[$organization_id]['mail_subject'];
							$raw_body_content = $template_data_arr[$organization_id]['mail_body'];

						} elseif( array_key_exists(0,$template_data_arr) ){

							$raw_mail_subject = $template_data_arr[0]['mail_subject'];
							$raw_body_content = $template_data_arr[0]['mail_body'];

						} else {
							$raw_mail_subject = null;
							$raw_body_content = null;
						}


						// If email template loaded then send replace short code & send email else return
						if ($raw_mail_subject && $raw_body_content) {
		
							$search = array(
								'@org_name', '@name', '@email', '@date', '@date_time', '@logo', '@message'
							);
							$replace = array(
								$organization_name, $userName, $userEmail, date('d-M-Y'), date('d-M-Y H:i A'), $logo, $emailMessage
							);
							$subject = str_replace($search, $replace, $raw_mail_subject);
							$body = str_replace($search, $replace, $raw_body_content);

						} else {
							return true;
						}

						// Creating array to pass in its respective mail template  $emailIds
						$arrData = array(
							'body_msg' => $body,
							'name' => $userName,
							'to' => $emailIds,
							'to_name' => $userName,
							'subject' => $subject,
							'from' => $from,
							'from_name' => $from_name,
						);

						$response = app('App\Http\Controllers\CommonController')->sendMail($arrData);

						\Storage::disk('local')->append('sync_failed_notification.txt', 'Sync_failed_notification send status - '.$response. ' send to '.$emailIds .PHP_EOL); 

					}

				}
				
			} 

					

		}
	}