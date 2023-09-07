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


	class AccountNotificationEmails extends Command
	{
		public static $defaultToName = 'Team';
		public $defaultMailgunApiKey = '';
		public $defaultMailgunApiUrl = '';

		/**
		* The name and signature of the console command.
		*
		* @var string
		*/
		protected $signature = 'command:AccountNotificationEmails';
		
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


			//Get users having... issue in connected account auth/token/other
			$error_msg = ['access token is expired, please refresh it','API Error:Unauthorized','API Error'];

			//first check in User & Account table if not found then.. check sub event table for common api error msg
			$ActiveUserList = DB::table('users as users')
			->select('users.id as user_id','users.organization_id','users.name','users.email','esnm.emails')
			->join('platform_accounts as ac','users.id','ac.user_id')
			->join('es_notification_email as esnm','users.id','esnm.user_id')
			->where([ 'users.status'=>1, 'ac.error_in_api_call'=> 1 ])
			//where notification_email_send_on not send today
			->whereDate('ac.notification_email_send_on', '!=', date('Y-m-d'))
			->groupBy('ac.user_id')
			->get();


			if( count($ActiveUserList) < 1 ) {

				$ActiveUserList = DB::table('users as users')
				->select('users.id as user_id','users.organization_id','users.name','users.email','esnm.emails')
				->join('platform_accounts as ac','users.id','ac.user_id')
				->join('es_notification_email as esnm','users.id','esnm.user_id')
				//join user integration by platform
				->join('user_integrations As ui', function ($join) {
					$join->on('ui.selected_sc_account_id', '=', 'ac.id')->orOn('ui.selected_dc_account_id', '=', 'ac.id');
				})
				//join user_integration_sub_event
				->join('user_integration_sub_event As uise','ui.id','uise.user_integration_id')
				->where('uise.status','failed')
				//where notification_email_send_on not send today
				->whereDate('ac.notification_email_send_on', '!=', date('Y-m-d'))
				//where user_integration_sub_event
				->whereIn('uise.message',$error_msg)
				->groupBy('ac.user_id')
				->get();

			}


			if( count($ActiveUserList) > 0)
			{
				//Loop active users having problem
				foreach($ActiveUserList as $userList){
					
					$userId = $userList->user_id;
					$userName = $userList->name;
					$userEmail = $userList->email;
					$organization_id = $userList->organization_id;


					//Get platform & UserIntegration details having problem
					$list_integrations = DB::table('platform_accounts as ac')
					->select('ac.id as accountId','ac.account_name','ac.error_msg','pl.platform_id','ui.id as userIntegId','ui.flow_name')
					->join('users as users','ac.user_id','users.id')
					->join('platform_lookup as pl','ac.platform_id','pl.id')
					->join('user_integrations As ui', function ($join) {
						$join->on('ui.selected_sc_account_id', '=', 'ac.id')->orOn('ui.selected_dc_account_id', '=', 'ac.id');
					})
					->where([ 'ac.error_in_api_call'=> 1 ])
					//where notification_email_send_on not send today
					->whereDate('ac.notification_email_send_on', '!=', date('Y-m-d'))
					->where('ac.user_id',$userId)
					->groupBy('ac.platform_id')
					->get();

				
					if( count($list_integrations) < 1 ) {

						$list_integrations = DB::table('platform_accounts as ac')
						->select('ac.id as accountId','ac.account_name','ac.error_msg','pl.platform_id','uise.message as error_msg','ui.id as userIntegId','ui.flow_name')
						->join('platform_lookup as pl','ac.platform_id','pl.id')
						->join('user_integrations As ui', function ($join) {
							$join->on('ui.selected_sc_account_id', '=', 'ac.id')->orOn('ui.selected_dc_account_id', '=', 'ac.id');
						})
						//join user_integration_sub_event
						->join('user_integration_sub_event As uise','ui.id','uise.user_integration_id')
						->where('ac.user_id',$userId)
						->where('uise.status','failed')
						//where user_integration_sub_event
						->whereIn('uise.message',$error_msg)
						->groupBy('ac.platform_id')
						->get();

					} 

					$emailMessage = "";
					$checkSyncFailed = false;

					$email_send_for_accountids = [];
					if($list_integrations) {	


						$emailMessage .='<table style="border: 1px solid black;border-collapse: collapse;width:100%;text-align:center;">
						<thead>
						<tr style="border: 1px solid black;border-collapse: collapse;">
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">#</th>
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Integration Id</th>
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Integration Flow Name</th>
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Account Name</th>
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Platform</th>
						<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Error Msg</th>
						</tr>
						</thead>
						<tbody>';

						$i = 0;
						foreach($list_integrations as $integration_row) {

							//enable send email when any record found
							$checkSyncFailed = true;

							$accountId = $integration_row->accountId;
							array_push($email_send_for_accountids,$accountId);

							$account_name = $integration_row->account_name;
							$message = $integration_row->error_msg;
							$platform_id = $integration_row->platform_id;
							$integrationId = $integration_row->userIntegId;
							$flowName = $integration_row->flow_name;

						
							$i++;
							$emailMessage .='<tr style="border: 1px solid black;border-collapse: collapse;">
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$i.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$integrationId.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$flowName.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$account_name.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$platform_id.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$message.'</td>
							</tr>';


						}

						$emailMessage .='</tbody></table>';
						
						

					}

					// if sync Failed Record Found for current User
					if($checkSyncFailed){

						//get organization details
						$organization_name = "";
						$logo = "";
						if (array_key_exists($organization_id,$organization_data_arr)){
							$organization_name = $organization_data_arr[$organization_id]['name'];
							$public_path = $organization_data_arr[$organization_id]['logo_url'];
							$logo_src = env('CONTENT_SERVER_PATH') . $public_path;
							$logo = '<img src="' . $logo_src . '" alt="'.$organization_name.'" style="margin-left:30%;width:30%;"><br>';
						} 

						//get notification email IDs
						if($userList->emails)
						{
							$emailIds = preg_replace('/\s+/', '', trim($userList->emails));
						} else {
							$emailIds = $userList->email;
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
							// 'to' => $emailIds,
							'to' => 'gajendrasahu.constacloud@gmail.com',
							'to_name' => $userName,
							// 'subject' => $subject,
							'subject' => 'Api call Failed Email',
							'from' => $from,
							'from_name' => $from_name,
						);
						
						$response = app('App\Http\Controllers\CommonController')->sendMail($arrData);

						if($response) {
							//Update users table for notification_email_send_on update.. & reset error_in_api_call 
							DB::table('platform_accounts')->whereIn('id',$email_send_for_accountids)->update(['notification_email_send_on'=>date('Y-m-d H:i:s'),'error_in_api_call'=>0]);
						}



					}
					

				}

				
			} 

					

		}
	}