<?php

namespace App\Helper;

use App\Helper\MainModel;
use DB;

class Logger extends MainModel
{
	/**
	 * Creates or updates a sync log data
	 * @param $user_id
	 * @param $user_integration_id
	 * @param $user_workflow_rule_id
	 * @param $source_platform_id
	 * @param $destination_platform_id
	 * @param $sync_type
	 * @param $sync_status
	 * @param $record_id
	 * @param $response
	 *
	 *
	 * @return
	 */
	public function syncLog($user_id = 0, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_type, $sync_status, $record_id = '0', $response)
	{

		$fields = array('user_workflow_rule_id' => $user_workflow_rule_id, 'platform_object_id' => $sync_type, 'source_platform_id' => $source_platform_id, 'destination_platform_id' => $destination_platform_id, 'record_id' => $record_id, 'user_id' => $user_id);
		$found = $this->getFirstResultByConditions('sync_logs', $fields, ['id']);
		if ($found) {
			$this->makeUpdate('sync_logs', ['sync_status' => $sync_status, 'status' => '1', 'timestamp' => time(), 'response' => $response], $fields);
		} else {
			$fields['sync_status'] = $sync_status;
			$fields['response'] = $response;
			$fields['timestamp'] = time();
			$this->makeInsert('sync_logs', $fields);
		}
	}

	public function syncLogBulk($user_id = 0, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $sync_type, $sync_status, $record_ids = [], $response)
	{


		foreach ($record_ids as $record_id) {
			$fields = array('user_workflow_rule_id' => $user_workflow_rule_id, 'platform_object_id' => $sync_type, 'source_platform_id' => $source_platform_id, 'destination_platform_id' => $destination_platform_id, 'record_id' => $record_id, 'user_id' => $user_id);
			$found = $this->getFirstResultByConditions('sync_logs', $fields, ['id']);
			if ($found) {
				$this->makeUpdate('sync_logs', ['sync_status' => $sync_status, 'status' => '1', 'timestamp' => time(), 'response' => $response], $fields);
			} else {
				$fields['sync_status'] = $sync_status;
				$fields['response'] = $response;
				$fields['timestamp'] = time();
				$this->makeInsert('sync_logs', $fields);
			}
		}

		/*
		$log_data = [];
		foreach ($record_ids as $record_id) {
			$log_data[] = array('user_id' => $user_id, 'user_workflow_rule_id' => $user_workflow_rule_id, 'platform_object_id' => $sync_type, 'source_platform_id' => $source_platform_id, 'destination_platform_id' => $destination_platform_id, 'record_id' => $record_id, 'sync_status' => $sync_status, 'status' => '1', 'timestamp' => time(), 'response' => $response);
		}

		if (count($log_data)) {
			DB::table('sync_logs')->upsert($log_data, ['record_id', 'platform_object_id', 'user_workflow_rule_id', 'user_id', 'source_platform_id', 'destination_platform_id'], ['sync_status', 'status', 'timestamp', 'response']);
		}
		*/
	}
}
