<?php
	namespace App\Http\Controllers;

	use Illuminate\Http\Request;
	use Illuminate\Support\Facades\Auth;
	use Illuminate\Support\Facades\Session;
	use App\Helper\MainModel;
	use App\Helper\WorkflowSnippet;
	use DB;
	use App\Http\Controllers\PanelControllers\ModuleAccessController;
	use Illuminate\Support\Facades\Log;
	use Illuminate\Support\Carbon;

	class WorkflowController extends Controller
	{
		public $wfsnip, $mobj;

		public function __construct()
		{
			$this->mobj = new MainModel();
			$this->wfsnip = new WorkflowSnippet();
		}

		public function index(Request $request)
		{
			$view = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
			if($view == 0){
				return redirect()->route('home.integrations');
			}

			$user_data = Session::get('user_data');
			$user_id = $user_data['user_id'];
			$wfid = $request->id;
			$platforms = $this->wfsnip->getPlatformOptions();

			return view("pages.manage_workflow", compact('platforms'));
		}

		public function CronWorkInitialGetData($user_workflow_rule_id)
		{
			set_time_limit(0);
			date_default_timezone_set('UTC');

			try {

				// \Storage::disk('local')->append('kernel_log.txt', 'Wf-Ctrl initial arrive : ' . $user_workflow_rule_id . ' time: '
                // . date('Y-m-d H:i:s'));

				//return the both platforms and their events ids queried from platform_workflow_rule table
				$getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
				if ($getflowEvents) {
					$user_id = $getflowEvents->user_id;
					$user_integration_id = $getflowEvents->user_integration_id;

					$eventExtract = $this->wfsnip->ExtractEventType($getflowEvents->source_event); //return method type (GET,MUTATE) and primary events (PURCHASEORDER,SALESORDER) in array
					$is_initial_sync = 1;
					if ($eventExtract['method'] == 'GET') { //handle GET events

						//set workflow status to inprocess
						$last_run_updated_arr = ['is_all_data_fetched' => 'inprocess'];
						$this->mobj->makeUpdate('user_workflow_rule', $last_run_updated_arr, ['id' => $user_workflow_rule_id]);

						//set call execute limit for break subevent call in chunks
						$is_all_good = 0;
						$count_call_execute_event = 0;
						$call_execute_event_limit = 4;

						//source platform primary event and sub events process.
						//primary event is represents the main event to be processed and the event which need to be process under primary events are representing as sub events here.
						$source_subevent_lookup = $this->mobj->getResultByConditions('platform_sub_event', ['platform_event_id' => $getflowEvents->source_event_id, 'status' => 1, 'prefetch' => 0], ['id', 'name', 'is_primary'], ['priority' => 'asc']);
						if (count($source_subevent_lookup)) {
							foreach ($source_subevent_lookup as $subevent) {

								//break initial sync if count_execute_event > call_execute_event_limit & return to kernel to call another workflow
								if ($count_call_execute_event >= $call_execute_event_limit) {
									$this->mobj->makeUpdate('user_workflow_rule', ['is_all_data_fetched' => 'pending'], ['id' => $user_workflow_rule_id]);
									return;
								}
								//end

								$flag = true;
								$user_intg_similar_evt = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->source_platform_id); // it returns whether same event is already exists for same user integration
								$is_failed = false; // for failed status
								if (count($user_intg_similar_evt)) {
									if ($user_intg_similar_evt[0]['status'] == 'inprocess') {
										$status = ['status' => 'inprocess'];
										$flag = false;
										} else if ($user_intg_similar_evt[0]['status'] == 'completed') {
										$status = ['status' => 'completed'];
										$flag = false;
										} else if ($user_intg_similar_evt[0]['status'] == 'failed') {
										$flag = true;
										$is_failed = true;
									}

									if (!$flag) {
										$update_similar_event = array_column($user_intg_similar_evt, 'sub_event_id'); // pick sub_event_id in a separate array
										if (in_array($subevent->id, $update_similar_event)) {
											DB::table('user_integration_sub_event')->where(['user_integration_id' => $user_integration_id])
                                            ->whereIn('sub_event_id', $update_similar_event)
                                            ->update($status);
											} else {
											$insert = array_merge($status, ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id]);
											$new_user_sub_evt_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);
											if ($user_intg_similar_evt[0]['status'] == 'completed') {
												$response = true;
												$resp = $this->wfsnip->subeventStatusUpdate($response, $new_user_sub_evt_id, $user_workflow_rule_id, 1, $user_integration_id);
											}
										}
									}
								}

								if ($flag) {
									if ($is_failed) {
										$user_intg_similar_evt_failed = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->source_platform_id, 'failed');
										$user_intg_similar_evt_arr = json_decode($user_intg_similar_evt_failed, true);
										$similar_failed_events = array_column($user_intg_similar_evt_arr, 'sub_event_id'); // pick sub_event_id in a separate array
										if (in_array($subevent->id, $similar_failed_events)) {
											foreach ($user_intg_similar_evt_failed as $failed) {
												$this->mobj->makeUpdate('user_integration_sub_event', ['status' => 'inprocess'], ['user_integration_id' => $user_integration_id, 'sub_event_id' => $failed->sub_event_id]);
											}
											} else {
											$insert = ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id, 'status' => 'inprocess'];
											$user_subevent_row_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);
										}

										//// GET functions need to return some response so that it can be used to handle the status of that sub event.
                                         /*  Here please focus on 3rd and 8th parameter |
                                    Due to GET Method
                                    3rd param is Destination platform
                                    8th param is Source platform */
										$response = $this->executeEvent($eventExtract['method'], $subevent->name, $getflowEvents->destination_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $getflowEvents->source_platform, '');

										//update Execute events call
										$count_call_execute_event++;
										// \Storage::disk('local')->append('initial_sync_log.txt','Chunk - Source SubEvent '.$subevent->name.' user_workflow_rule_id '.$user_workflow_rule_id);


										////handling sub events status, if failed then set user workflow rule as failed.
										//// it returns whether "failed" events are already exists for same event_name and user_integration
										$user_intg_similar_evt_inprocess = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->source_platform_id, 'inprocess');

										foreach ($user_intg_similar_evt_inprocess as $inprocess) {
											$resp = $this->wfsnip->subeventStatusUpdate($response, $inprocess->id, $user_workflow_rule_id, 1, $user_integration_id);
										}
										//set error flag
										if (!$resp) {
											$is_all_good++;
											break;
										}
										} else {
										$insert = ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id, 'status' => 'inprocess'];
										$user_subevent_row_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);

										//// GET functions need to return some response so that it can be used to handle the status of that sub event.
                                          /*  Here please focus on 3rd and 8th parameter |
                                    Due to GET Method
                                    3rd param is Destination platform
                                    8th param is Source platform */
										$response = $this->executeEvent($eventExtract['method'], $subevent->name, $getflowEvents->destination_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $getflowEvents->source_platform, '');

										//update Execute events call
										$count_call_execute_event++;
										// \Storage::disk('local')->append('initial_sync_log.txt','Chunk - Source SubEvent '.$subevent->name.' user_workflow_rule_id '.$user_workflow_rule_id);

										////handling sub events status, if failed then set user workflow rule as failed.
										//// it returns whether "inprocess" events are already exists for same event_name and user_integration
										$user_intg_similar_evt_inprocess = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->source_platform_id, 'inprocess');
										foreach ($user_intg_similar_evt_inprocess as $row) {
											$resp = $this->wfsnip->subeventStatusUpdate($response, $row->id, $user_workflow_rule_id, 1, $user_integration_id);
										}
										if ( isset($resp) && !$resp) {
											$is_all_good++;
											break;
										}
									}
								}
							}
						}

						//destination platform primary event and sub events process.
						//primary event is represents the main event to be processed and the event which need to be process under primary events are representing as sub events here.
						$destination_subevent_lookup = $this->mobj->getResultByConditions('platform_sub_event', ['platform_event_id' => $getflowEvents->destination_event_id, 'status' => 1, 'prefetch' => 0], ['id', 'name', 'is_primary'], ['priority' => 'asc']);
						if (count($destination_subevent_lookup)) {
							foreach ($destination_subevent_lookup as $subevent) {

								//break initial sync if count_execute_event > call_execute_event_limit & return to kernel to call another workflow
								if ($count_call_execute_event >= $call_execute_event_limit) {
									// \Storage::disk('local')->append('initial_sync_log.txt',''.PHP_EOL);
									$this->mobj->makeUpdate('user_workflow_rule', ['is_all_data_fetched' => 'pending'], ['id' => $user_workflow_rule_id]);
									return;
								}
								//end

								$flag = true;
								$user_intg_similar_evt = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->destination_platform_id); // it returns whether same event is already exists for same user integration
								$is_failed = false;
								if (count($user_intg_similar_evt)) {
									if ($user_intg_similar_evt[0]['status'] == 'inprocess') {
										$status = ['status' => 'inprocess'];
										$flag = false;
										} else if ($user_intg_similar_evt[0]['status'] == 'completed') {
										$status = ['status' => 'completed'];
										$flag = false;
										} else if ($user_intg_similar_evt[0]['status'] == 'failed') {
										$flag = true;
										$is_failed = true;
									}

									if (!$flag) {
										$update_similar_event = array_column($user_intg_similar_evt, 'sub_event_id'); // pick sub_event_id in a separate array
										if (in_array($subevent->id, $update_similar_event)) {
											DB::table('user_integration_sub_event')->where(['user_integration_id' => $user_integration_id])
                                            ->whereIn('sub_event_id', $update_similar_event)
                                            ->update($status);
											} else {
											$insert = array_merge($status, ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id]);
											$new_user_sub_evt_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);
											if ($user_intg_similar_evt[0]['status'] == 'completed') {
												$response = true;
												$resp = $this->wfsnip->subeventStatusUpdate($response, $new_user_sub_evt_id, $user_workflow_rule_id, 1, $user_integration_id);
											}
										}
									}
								}

								if ($flag) {
									if ($is_failed) {
										$user_intg_similar_evt_failed = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->destination_platform_id, 'failed');
										$user_intg_similar_evt_arr = json_decode($user_intg_similar_evt_failed, true);
										$similar_failed_events = array_column($user_intg_similar_evt_arr, 'sub_event_id'); // pick sub_event_id in a separate array
										if (in_array($subevent->id, $similar_failed_events)) {
											foreach ($user_intg_similar_evt_failed as $failed) {
												$this->mobj->makeUpdate('user_integration_sub_event', ['status' => 'inprocess'], ['user_integration_id' => $user_integration_id, 'sub_event_id' => $failed->sub_event_id]);
											}
											} else {
											$insert = ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id, 'status' => 'inprocess'];
											$user_subevent_row_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);
										}

										//// GET functions need to return some response so that it can be used to handle the status of that sub event.
                                           /*  Here please focus on 3rd and 8th parameter |
                                    Due to MUTATE'S GET Method
                                    3rd param is Source platform
                                    8th param is Destination platform */
										$response = $this->executeEvent($eventExtract['method'], $subevent->name, $getflowEvents->source_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $getflowEvents->destination_platform, '');

										//update Execute events call
										$count_call_execute_event++;
										// \Storage::disk('local')->append('initial_sync_log.txt','Chunk - Destination SubEvent '.$subevent->name.' user_workflow_rule_id '.$user_workflow_rule_id);

										////handling sub events status, if failed then set user workflow rule as failed.
										//// it returns whether "failed" events are already exists for same event_name and user_integration
										$user_intg_similar_evt_inprocess = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->destination_platform_id, 'inprocess');
										foreach ($user_intg_similar_evt_inprocess as $inprocess) {
											$resp = $this->wfsnip->subeventStatusUpdate($response, $inprocess->id, $user_workflow_rule_id, 1, $user_integration_id);
										}
										//set error flag
										if (!$resp) {
											$is_all_good++;
											break;
										}
										} else {
										$insert = ['user_integration_id' => $user_integration_id, 'sub_event_id' => $subevent->id, 'status' => 'inprocess'];
										$user_subevent_row_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);

										//// GET functions need to return some response so that it can be used to handle the status of that sub event.
                                         /*  Here please focus on 3rd and 8th parameter |
                                    Due to MUTATE'S GET Method
                                    3rd param is Source platform
                                    8th param is Destination platform */
										$response = $this->executeEvent($eventExtract['method'], $subevent->name, $getflowEvents->source_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $getflowEvents->destination_platform, '');

										//update Execute events call
										$count_call_execute_event++;
										// \Storage::disk('local')->append('initial_sync_log.txt','Chunk - Destination SubEvent '.$subevent->name.' user_workflow_rule_id '.$user_workflow_rule_id);

										//// handling sub events status, if failed then set user workflow rule as failed.
										//// it returns whether "inprocess" events are already exists for same event_name and user_integration
										$user_intg_similar_evt_inprocess = $this->wfsnip->checkSimilarEventExists($user_integration_id, $subevent->name, $getflowEvents->destination_platform_id, 'inprocess');
										foreach ($user_intg_similar_evt_inprocess as $row) {
											$resp = $this->wfsnip->subeventStatusUpdate($response, $row->id, $user_workflow_rule_id, 1, $user_integration_id);
										}
										//set error flag
										if (!$resp) {
											$is_all_good++;
											break;
										}
									}
								}
							}
						}


						//prevent to set status completed if anything goes wrong in the above process.
						if ($is_all_good == 0) {
							// $last_run_updated_arr = ['last_run_updated_at' => now(), 'is_all_data_fetched' => 'completed'];
							$last_run_updated_arr = ['last_run_updated_at' => date('Y-m-d H:i:s'), 'is_all_data_fetched' => 'completed'];
							$this->mobj->makeUpdate('user_workflow_rule', $last_run_updated_arr, ['id' => $user_workflow_rule_id]);
						}
					}
				}
			}
			catch (\Exception $e) {
				\Log::error('WorkflowController - CronWorkInitialGetData - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}

		/*
			sEventId : pass this parameter if need to run additional full inventory sync cron based on selected frequencies
		*/
		public function CronWorkflowGetData($user_workflow_rule_id, $sEventId)
		{
			set_time_limit(0);
			date_default_timezone_set('UTC');

			// \Storage::disk('local')->append('kernel_log.txt', 'Wf-Ctrl getData arrive : ' . $user_workflow_rule_id . ' time: ' . date('Y-m-d H:i:s'));

			try {
				//return the both platforms and their events ids queried from platform_workflow_rule table
				$getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);

				if ($getflowEvents) {
					$user_id = $getflowEvents->user_id;
					$user_integration_id = $getflowEvents->user_integration_id;
					$source_platform_id = $getflowEvents->source_platform;
					$sourceEventExtract = $this->wfsnip->ExtractEventType($getflowEvents->source_event);

					//handle GET events
					if ($sourceEventExtract['method'] == 'GET') {
						$is_initial_sync = 0;
						$source_subevent_lookup_query = DB::table('platform_sub_event')
                        ->where([
                            'platform_event_id' => $getflowEvents->source_event_id,
                            'status' => 1
                        ]);
						//add additional where class for Get_INVENTORY / FULLINVENTORY
						if ($sEventId) {
							$source_subevent_lookup_query->where('name', $sEventId);
                        } else {
							$source_subevent_lookup_query->where(function ($query1) {
								$query1->orWhere(['run_backup' => 1, 'is_primary' => 1]);
							});
						}

						$source_subevent_lookup = $source_subevent_lookup_query->orderby('is_primary', 'DESC')
                        ->select('is_primary', 'id', 'run_in_min', 'name')->get();

						//log selected source subevent
						// \Storage::disk('local')->append('kernel_log.txt', 'WFctrl-Get uwf-: ' . $user_workflow_rule_id . ' SourceSubEvent'
						//     . json_encode($source_subevent_lookup, true) . ' time: ' . date('Y-m-d H:i:s'));

						foreach ($source_subevent_lookup as $subevent_lookup) {

							$user_integration_subevent_lookup = DB::table('user_integration_sub_event')->select('id', 'last_run_time')->where('user_integration_id', $user_integration_id)->where('sub_event_id', $subevent_lookup->id)->first();

							$run_event = 0;
							if ($subevent_lookup->is_primary == 1) {
								//execute event
								$run_event = 1;
								} else { // pulling backup data

								if ($user_integration_subevent_lookup) {
									$new_time = strtotime("+" . $subevent_lookup->run_in_min . ' minutes', strtotime($user_integration_subevent_lookup->last_run_time));
									$curr_time = time();
									if ($curr_time >= $new_time) {
										$run_event = 1;
									}
								}
							}

							if ($run_event && $user_integration_subevent_lookup) { //pulling primary event and primary object data .
								$this->wfsnip->subeventStatusUpdate('', $user_integration_subevent_lookup->id, $user_workflow_rule_id, 0, $user_integration_id, 'inprocess');
								$response = $this->executeEvent($sourceEventExtract['method'], $subevent_lookup->name, $getflowEvents->destination_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $getflowEvents->platform_workflow_rule_id);
								// $last_run_updated_arr = ['last_run_updated_at' => now()];
								$last_run_updated_arr = ['last_run_updated_at' => date('Y-m-d H:i:s')];

								// \Storage::disk('local')->append('execute_new_eve.txt.txt', 'pulling primary event and primary object data Resp :' .$response. ' UserWFR : '.$user_workflow_rule_id.' SubEventId : '.$subevent_lookup->id. ' LastRunUpdateAt : '.date('Y-m-d H:i:s'));

								$this->mobj->makeUpdate('user_workflow_rule', $last_run_updated_arr, ['id' => $user_workflow_rule_id]);
								$this->wfsnip->subeventStatusUpdate($response, $user_integration_subevent_lookup->id, $user_workflow_rule_id, 0, $user_integration_id);
							}
						} //foreach loop end..

						$destination_subevent_lookup = DB::table('platform_sub_event')
                        ->where([
                            'platform_event_id' => $getflowEvents->destination_event_id,
                            'status' => 1,
                            'run_backup' => 1
                        ])
                        ->select('is_primary', 'id', 'run_in_min', 'name')
                        ->get();

						//log selected dest subevent
						// \Storage::disk('local')->append('kernel_log.txt', 'WFctrl-Get uwf-: ' . $user_workflow_rule_id . ' DestSubEvent'
						//     . json_encode($destination_subevent_lookup, true) . ' time: ' . date('Y-m-d H:i:s'));

						//pulling destination side backup object data .

						foreach ($destination_subevent_lookup as $destination_lookup) {
							$run_destination_event = 0;
							$user_integration_deserved_lookup = DB::table('user_integration_sub_event')->select('id', 'last_run_time')->where('user_integration_id', $user_integration_id)->where('sub_event_id', $destination_lookup->id)->first();

							if ($user_integration_deserved_lookup) {
								$new_time = strtotime("+" . $destination_lookup->run_in_min . ' minutes', strtotime($user_integration_deserved_lookup->last_run_time));
								$curr_time = time();
								if ($curr_time > $new_time) {
									//execute event
									$run_destination_event = 1;
								}
							}

							if ($run_destination_event && $user_integration_deserved_lookup) {
								$this->wfsnip->subeventStatusUpdate('', $user_integration_deserved_lookup->id, $user_workflow_rule_id, 0, $user_integration_id, 'inprocess');

								// $response = $this->executeEvent($sourceEventExtract['method'], $destination_lookup->name, $getflowEvents->destination_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $getflowEvents->platform_workflow_rule_id);

								/*  Here please focus on 3rd and 8th parameter |
                                    Due to MUTATE'S GET Method
                                    3rd param is Source platform
                                    8th param is Destination platform
								*/
								$response = $this->executeEvent($sourceEventExtract['method'], $destination_lookup->name, $getflowEvents->source_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $getflowEvents->destination_platform, $getflowEvents->platform_workflow_rule_id);


								// $last_run_updated_arr = ['last_run_updated_at' => now()];
								$last_run_updated_arr = ['last_run_updated_at' => date('Y-m-d H:i:s')];

								// \Storage::disk('local')->append('execute_new_eve.txt.txt', 'pulling primary event and primary object data (Dest) Resp : ' .$response. ' UserWFR : '.$user_workflow_rule_id.' SubEventId : '.$destination_lookup->id. ' LastRunUpdateAt : '.date('Y-m-d H:i:s'));

								$this->mobj->makeUpdate('user_workflow_rule', $last_run_updated_arr, ['id' => $user_workflow_rule_id]);
								$this->wfsnip->subeventStatusUpdate($response, $user_integration_deserved_lookup->id, $user_workflow_rule_id, 0, $user_integration_id);
							}
						} //foreach loop end..
					}
				}
			}
			catch (\Exception $e) {
				\Log::error('WorkflowController - CronWorkflowGetData - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}

		public function CronWorkflowMutationData($user_workflow_rule_id)
		{
			set_time_limit(0);
			date_default_timezone_set('UTC');

			// \Storage::disk('local')->append('kernel_log.txt', 'Wf-Ctrl mutateData arrive : ' . $user_workflow_rule_id . ' time: ' . date('Y-m-d H:i:s'));

			try {
				//return the both platforms and their events ids queried from platform_workflow_rule table
				$getflowEvents = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);

				// echo '<pre>';print_r($getflowEvents);exit;

				if ($getflowEvents) {

					$user_id = $getflowEvents->user_id;
					$user_integration_id = $getflowEvents->user_integration_id;
					$source_platform_id = $getflowEvents->source_platform;
					//destination event process
					$destinationEventExtract = $this->wfsnip->ExtractEventType($getflowEvents->destination_event); //return method type (GET,MUTATE) and primary events (PURCHASEORDER,SALESORDER) in array

					if ($destinationEventExtract['method'] == 'MUTATE') {

						$is_initial_sync = 0;
						//execute event
						$this->executeEvent($destinationEventExtract['method'], $destinationEventExtract['primary_event'], $getflowEvents->destination_platform, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $getflowEvents->platform_workflow_rule_id);

						// $last_run_updated_arr = ['last_run_updated_at' => now()];
						$last_run_updated_arr = ['last_run_updated_at' => date('Y-m-d H:i:s')];
						$this->mobj->makeUpdate('user_workflow_rule', $last_run_updated_arr, ['id' => $user_workflow_rule_id]);
					}
				}
			}
			catch (\Exception $e) {
				\Log::error('WorkflowController - CronWorkflowMutationData - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}

		public function executeEvent($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$logFileName = 'execute_event_'.date('Y-m-d').'.txt';
			\Storage::disk('local')->append($logFileName, 'Met: ' . $method . ' Eve: ' . $event . ' Source:' . $source_platform_id . ' Dest: ' . $destination_platform_id . ' intid: ' . $user_integration_id . ' wfid: ' . $user_workflow_rule_id . ' is_initial_sync: ' . $is_initial_sync . ' Arrival time: ' . date('Y-m-d H:i:s'));

			//Log bulk inventory sync & Ignore call subevent
			if($event=="BULKINVENTORY" || $event=="MARKUNMATCHEDPRODINVENTORYIGNORE" || $event=="INVENTORYSNAPSHOT" || $event=="INVENTORYSNAPSHOTSTORE") {
				$logFileNameCustom = 'execute_event_custom_'.date('Y-m-d').'.txt';
				\Storage::disk('local')->append($logFileNameCustom, 'Met: ' . $method . ' Eve: ' . $event . ' Source:' . $source_platform_id . ' Dest: ' . $destination_platform_id . ' intid: ' . $user_integration_id . ' wfid: ' . $user_workflow_rule_id . ' Arrival time: ' . date('Y-m-d H:i:s'));
			}

			/** $record_id stands for primary key of source table i.e. platform_order or platform_products */
			try {
				////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
				return app('App\Http\Controllers\WorkflowExecuteEventManageController')->ExecuteEventManager($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
			}
			catch(\Exception $e)
			{
				\Log::error('WorkflowController - executeEvent - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}

		public function CronRefreshTokens()
		{
			try{
				$process_limit = 10;
				$skip = 0;
				$page = 0;

				do{
					date_default_timezone_set('UTC');
					$allow_next_call = false; // This flag will help for pagination
					$page++;

					$platform_accounts = DB::table('platform_accounts')->join('platform_lookup', 'platform_accounts.platform_id', '=', 'platform_lookup.id')
					->select('platform_accounts.id', 'platform_accounts.user_id', 'platform_accounts.token_refresh_time', 'platform_accounts.refresh_token', 'platform_accounts.expires_in', 'platform_accounts.account_name', 'platform_accounts.app_id', 'platform_accounts.app_secret', 'platform_accounts.env_type', 'platform_lookup.platform_id', 'platform_lookup.platform_name')
                    ->where('platform_accounts.status', 1)
					->where('platform_accounts.allow_refresh', 1)
                    ->orderBy('platform_accounts.updated_at', 'asc')
                    ->skip($skip)
                    ->take($process_limit)
                    ->get();

					if(count($platform_accounts) == $process_limit)
                    {
                        //Make it false as well if we want to avoid continuous loop
						$allow_next_call = true;
						$skip = $page * $process_limit;
					}

					foreach($platform_accounts as $platform_account)
                    {
						$token_refresh_time = $platform_account->token_refresh_time;
						//Get minute diff = tokenRefTime + expireIn - current Time
						$minuteDifference = round((strtotime('+' . $platform_account->expires_in . ' sec', $token_refresh_time) - strtotime(date("Y-m-d H:i:s"))) / 60);

						\Storage::disk('local')->append('testCrone.txt', 'Refresh: '.$platform_account->id.' - '.$platform_account->platform_id.', Tok_Ref_Time sec: '.$token_refresh_time.', ExpIN sec: '.$platform_account->expires_in.' Current Time: '.strtotime(date("Y-m-d H:i:s")).', minDIFF: '.$minuteDifference);

						//\Log::channel('webhook')->info("REFRESH-TOKEN -> User ID: ".$platform_account->user_id.", Account ID: ".$platform_account->id.", DB REFRESH TIME: ".$platform_account->token_refresh_time.", Exp. in Seconds: ".$platform_account->expires_in.",  DIFF: ".$minuteDifference);

						app('App\Http\Controllers\WorkflowExecuteEventManageController')->ExecuteRefreshTokenManager($platform_account->id, $platform_account->user_id, $platform_account->platform_id, $platform_account->account_name, $platform_account->app_id, $platform_account->app_secret, $platform_account->refresh_token, $platform_account->env_type, $minuteDifference);
					}
				}
                while($allow_next_call);
			}
			catch(\Exception $e)
			{
				\Log::error('WorkflowController - CronRefreshTokens - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}

		//Tesing log
		public function getAccountTokenRefreshDetails(Request $request)
		{   

			$request_filter = $request->filter;

			$response_content = "<h2 style='text-align:center'>Account Token Refresh Details ".$request_filter. "</h2>";

			if($request_filter=="refreshEnabled") {
				$account_data = DB::table('platform_accounts as pac')->join('users','users.id','pac.user_id')->join('platform_lookup','platform_lookup.id','pac.platform_id')
				->select('pac.id as accountId','users.name','pac.account_name','pac.expires_in','pac.token_refresh_time','pac.token_refresh_time','platform_lookup.platform_name','platform_lookup.refresh_in_minute','pac.created_at','pac.updated_at')
				->where(['platform_lookup.allow_refresh'=>1])
				->orderby('pac.updated_at','desc')
				->get();
			} else if($request_filter=="InQueue") {

				$currentTimestamp = strtotime(Carbon::now()); 

				$account_data = DB::table('platform_accounts')
					->join('platform_lookup', 'platform_accounts.platform_id', '=', 'platform_lookup.id')
					->join('users','users.id','platform_accounts.user_id')
					->select('platform_accounts.id as accountId','users.name','platform_accounts.account_name','platform_accounts.expires_in','platform_accounts.token_refresh_time','platform_accounts.token_refresh_time','platform_lookup.platform_name','platform_lookup.refresh_in_minute','platform_accounts.created_at','platform_accounts.updated_at')

					//additional where condition added for filter records
					->where(DB::raw("platform_accounts.token_refresh_time + (platform_lookup.refresh_in_minute * 60)") ,'<',$currentTimestamp)
					->where('platform_accounts.status', 1)
					->where('platform_lookup.allow_refresh', 1)
					->orderBy('platform_accounts.updated_at', 'desc')
					->get();
				
			} else {
				$account_data = DB::table('platform_accounts as pac')->join('users','users.id','pac.user_id')->join('platform_lookup','platform_lookup.id','pac.platform_id')
				->select('pac.id as accountId','users.name','pac.account_name','pac.expires_in','pac.token_refresh_time','pac.token_refresh_time','platform_lookup.platform_name','platform_lookup.refresh_in_minute','pac.created_at','pac.updated_at')
				->orderby('pac.updated_at','desc')
				->get();
			}
			

			if($account_data)  {

				$response_content .='<table style="border: 1px solid black;border-collapse: collapse;width:100%;text-align:center;">
				<thead>
				<tr style="border: 1px solid black;border-collapse: collapse;">
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">#</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Account Name</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">User</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Platform</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Refresh in Minute</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Expire In Sec</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Token Refresh Time</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Created At</th>
				<th scope="col" style="border: 1px solid black;border-collapse: collapse;padding:3px">Updated At</th>
				</tr>
				</thead>
				<tbody>';


				if($account_data) {	
					$i = 0;
					foreach($account_data as $row) {
						$i++;

						if($request_filter=="InQueue") {
							$row = (array) $row;
							$row = (object) $row;
						}
						
						$response_content .='<tr style="border: 1px solid black;border-collapse: collapse;">
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$i.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->account_name.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->name.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->platform_name.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->refresh_in_minute.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->expires_in.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->token_refresh_time.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->created_at.'</td>
							<td style="border: 1px solid black;border-collapse: collapse;padding:3px">'.$row->updated_at.'</td>
							</tr>';
					}
					

					$response_content .='</tbody></table>';


				}

			}

			return $response_content;
			
		}




	}
