<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;

class WorkflowExecuteEventManageController extends Controller
{
	public function ExecuteEventManager($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
	{
		$response = true;

		if ($method == "GET") {
			//When method is GET change source_platform_id as destination
			$processPlatformName = $source_platform_id;
		} elseif ($method == "MUTATE") {
			//When method is MUTATE destination_platform_id should be destination only
			$processPlatformName = $destination_platform_id;
		}

		//Log for test
		// $logFileName = 'cron_running_test_log_'.date('Y-m-d').'.txt';
		// \Storage::disk('local')->append($logFileName, ' ExecuteEventManager call log ' . ' call time : '. date('Y-m-d H:i') . ' current timestamp : '.time()
		// .' source Event : '.$event. ' method : '.$method. ' user_integration_id : ' .$user_integration_id. ' processPlatformName :'.$processPlatformName.'' );


		if ($processPlatformName == 'wayfair') {
			$response = app('App\Http\Controllers\Wayfair\WayfairApiController')->ExecuteEventWayfair($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'skuvault') {
			$response = app('App\Http\Controllers\Skuvault\SkuvaultApiController')->ExecuteEventSkuvault($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'brightpearl') {
			$response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ExecuteBrightpearl($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'woocommerce') {
			$response = app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->ExecuteWoocommerce($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'intacct') {
			$response = app('App\Http\Controllers\Intacct\IntacctApiController')->ExecuteEventIntacct($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'spscommerce') {
			$response = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->ExecuteEventSpscommerce($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == '3dcart') {
			$response = app('App\Http\Controllers\ThreeDCart\ThreeDCartApiController')->Execute3dcartEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'netsuite') {
			$response = app('App\Http\Controllers\Netsuite\NetsuiteApiController')->ExecuteEventNetsuite($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'klaviyo') {
			$response = app('App\Http\Controllers\Klaviyo\KlaviyoApiController')->ExecuteEventKlaviyo($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'kefron') {
			$response = app('App\Http\Controllers\Kefron\KefronApiController')->ExecuteEventKefron($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'amazonvendor') {
			$response = app('App\Http\Controllers\Amazon\AmazonApiController')->ExecuteEventAmazon($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'amazonmcf') {
			$response = app('App\Http\Controllers\Amazon\AmazonMcfController')->ExecuteEventAmazonMCF($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'googlesheet') {
			$response = app('App\Http\Controllers\Google\GoogleSpreadsheetController')->ExecuteGoogleSpreadsheet($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'vikingbad') {
			$response = app('App\Http\Controllers\Vikingbad\VikingBadController')->ExecuteVikingBad($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'zulily') {
			$response = app('App\Http\Controllers\Zulily\ZulilyApiController')->ExecuteEventZulily($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == '3pl') {
			$response = app('App\Http\Controllers\ThreePL\ThreePLApiController')->ExecuteEvent3PL($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'extensivbillingmanager') {
			$response = app('App\Http\Controllers\ExtensivBillingManager\ExtensivBillingManagerApiController')->ExecuteExtensivBillingManagerEvent($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'cscart') {
			$response = app('App\Http\Controllers\CSCart\CSCartApiController')->ExecuteEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'shipbob') {
			$response = app('App\Http\Controllers\ShipBob\ShipBobApiController')->ExecuteShipBobEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'markettime') {
			$response = app('App\Http\Controllers\MarketTime\MarketTimeApiController')->ExecuteEventMarketTime($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'brandwise') {
			$response = app('App\Http\Controllers\Brandwise\BrandwiseController')->ExecuteBrandWise($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'reamaze') {
			$response = app('App\Http\Controllers\Reamaze\ReamazeApiController')->ExecuteRemazeApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'magento') {
			$response = app('App\Http\Controllers\Magento\MagentoApiController')->ExecuteEventMagento($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'tipalti') {
			$response = app('App\Http\Controllers\Tipalti\TipaltiApiController')->ExecuteEventTipalti($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'ahlsell') {
			$response = app('App\Http\Controllers\Ahlsell\AhlsellController')->ExecuteAhlsell($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'shiphawk') {
			$response = app('App\Http\Controllers\ShipHawk\ShipHawkController')->ExecuteShipHawkApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'heidenreich') {
			$response = app('App\Http\Controllers\Heidenreich\HeidenreichController')->ExecuteHeidenreich($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'ups') {
			$response = app('App\Http\Controllers\UPS\UPSController')->executeUPS($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'brodrenedahl') {
			$response = app('App\Http\Controllers\Brodrene\BrodreneController')->ExecuteBrodrene($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'teapplix') {
			$response = app('App\Http\Controllers\Teapplix\TeapplixApiController')->ExecuteTeapplixEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'squarespace') {
			$response = app('App\Http\Controllers\Squarespace\SquarespaceController')->ExecuteSquarespace($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'bigcommerce') {
			$response = app('App\Http\Controllers\Bigcommerce\BigcommerceController')->ExecuteBigCommerceEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'shiprush') {
			$response = app('App\Http\Controllers\ShipRush\ShipRushController')->ExecuteShipRush($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'shiphero') {
			$response = app('App\Http\Controllers\ShipHero\ShipHeroApiController')->ExecuteShipHeroEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'bluecherry') {
			$response = app('App\Http\Controllers\BlueCherry\BlueCherryApiController')->ExecuteBlueCherryEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'infoplus') {
			$response = app('App\Http\Controllers\Infoplus\InfoplusApiController')->ExecuteInfoplusEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'microchip') {
			$response = app('App\Http\Controllers\MicroChip\MicroChipApiController')->ExecuteMicroChipEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'cetecerp') {
			$response = app('App\Http\Controllers\CetecERP\CetecERPApiController')->ExecuteCetecERPEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'gunbroker') {
			$response = app('App\Http\Controllers\GunBroker\GunBrokerController')->ExecuteGunBroker($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'trailsend') {
			$response = app('App\Http\Controllers\Trailsend\TrailsendController')->ExecuteTrailsend($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'taxjar') {
			$response = app('App\Http\Controllers\TaxJar\TaxJarController')->ExecuteTaxJarEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'smartsheet') {
			$response = app('App\Http\Controllers\Smartsheet\SmartsheetApiController')->ExecuteSmartsheetEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'korsbakken') {
			$response = app('App\Http\Controllers\Korsbakken\KorsbakkenController')->ExecuteKorsbakken($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'whmcs') {
			$response = app('App\Http\Controllers\Whmcs\WhmcsApiController')->ExecuteWhmcsEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'hubspot') {
			$response = app('App\Http\Controllers\HubSpot\HubSpotApiController')->ExecuteHubSpotEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'jasci') {
			$response = app('App\Http\Controllers\Jasci\JasciController')->ExecuteEventJasci($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'quickbooks') {
			$response = app('App\Http\Controllers\QuickBooks\QuickBooksApiController')->ExecuteQuickBooksEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'shipstation') {
			$response = app('App\Http\Controllers\Shipstation\ShipstationController')->ExecuteShipstationApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'skubana') {
			$response = app('App\Http\Controllers\Skubana\SkubanaApiController')->ExecuteSkubanaEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'snowflake') {
			$response = app('App\Http\Controllers\Snowflake\SnowflakeApiController')->ExecuteSnowflakeEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'veracore') {
			$response = app('App\Http\Controllers\Veracore\VeracoreApiController')->ExecuteEventVeracore($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'tiktok') {
			$response = app('App\Http\Controllers\Tiktok\TiktokApiController')->ExecuteEventTiktok($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'exacterp') {
			$response = app('App\Http\Controllers\ExactERP\ExactERPApiController')->ExecuteExactERPEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'peoplevox') {
			$response = app('App\Http\Controllers\Peoplevox\PeoplevoxController')->ExecuteEventPeoplevox($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'jamesandjames') {
			$response = app('App\Http\Controllers\JamesAndJames\JamesApiController')->ExecuteEventJames($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'logiwa') {
			$response = app('App\Http\Controllers\Logiwa\LogiwaApiController')->ExecuteLogiwaEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'inventoryplanner') {
			$response = app('App\Http\Controllers\InventoryPlanner\InventoryPlannerApiController')->ExecuteInventoryPlannerEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		} elseif ($processPlatformName == 'sdmo') {
			$response = app('App\Http\Controllers\SDMO\SDMOController')->ExecuteSDMOEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
		}

		return $response;
	}

	public function ExecuteRefreshTokenManager($id, $user_id, $platform_id, $account_name, $app_id, $app_secret, $refresh_token, $env_type, $minuteDifference)
	{
		$response = true;

		if ($platform_id == 'spscommerce' && $minuteDifference < 10) {
			$response = app('App\Http\Controllers\Spscommerce\SpscommerceApiController')->GetAccessTokenUsingRefreshToken($user_id, $id, $refresh_token);
		} elseif ($platform_id == 'intacct' && $minuteDifference < 10) {
			$response = app('App\Http\Controllers\Intacct\IntacctApiController')->GetRefreshSession($user_id, $id, $account_name, $app_id, $app_secret);
		} elseif ($platform_id == 'wayfair' && $minuteDifference < 60) {
			$response = app('App\Http\Controllers\Wayfair\WayfairApiController')->RefreshTokens($id, $user_id, $app_id, $app_secret, $env_type);
		} elseif ($platform_id == 'brightpearl' && $minuteDifference < 60) {
			$success_response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->RefreshTokens($id, $user_id);
			$response = $success_response['response'];
		} elseif (($platform_id == 'amazonvendor' || $platform_id == 'amazonmcf') && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\Amazon\AmazonApiController')->refreshTokens($id, $user_id, $platform_id);
		} elseif ($platform_id == '3pl' && $minuteDifference < 49) {
			$response = app('App\Http\Controllers\ThreePL\ThreePLApiController')->RefreshTokens($id);
		} elseif ($platform_id == 'extensivbillingmanager' && $minuteDifference < 29) {
			//$response = app('App\Http\Controllers\ExtensivBillingManager\ExtensivBillingManagerApiController')->RefreshTokens($id);
		} elseif ($platform_id == 'shipbob' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\ShipBob\ShipBobApiController')->RefreshToken($id);
		} elseif ($platform_id == 'brodrenedahl' && $minuteDifference < 60) {
			$response = app('App\Http\Controllers\Brodrene\BrodreneController')->RefreshTokens($id);
		} elseif ($platform_id == 'squarespace' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\Squarespace\SquarespaceController')->RefreshTokens($id);
		} elseif ($platform_id == 'shiphero' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\ShipHero\ShipHeroApiController')->RefreshToken($id);
		} elseif ($platform_id == 'mftgateway' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\MFTGateway\MFTGatewayApiController')->RefreshToken($id);
		} elseif ($platform_id == 'gunbroker' && $minuteDifference < 15) {
			$response = app('App\Http\Controllers\GunBroker\GunBrokerController')->RefreshToken($id);
		} elseif ($platform_id == 'smartsheet' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\Smartsheet\SmartsheetApiController')->RefreshToken($id);
		} elseif ($platform_id == 'hubspot' && $minuteDifference < 15) {
			$response = app('App\Http\Controllers\HubSpot\HubSpotApiController')->RefreshToken($id);
		} elseif ($platform_id == 'jasci' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\Jasci\JasciController')->RefreshToken($id);
		} elseif ($platform_id == 'quickbooks' && $minuteDifference < 29) {
			$response = app('App\Http\Controllers\QuickBooks\QuickBooksApiController')->refreshToken($id);
		} elseif ($platform_id == 'snowflake' && $minuteDifference <= 5) {
			$response = app('App\Http\Controllers\Snowflake\SnowflakeApiController')->RefreshToken($id);
		} elseif ($platform_id == 'tiktok' && $minuteDifference < 59) {
			$response = app('App\Http\Controllers\Tiktok\TiktokApiController')->RefreshToken($id);
		} elseif ($platform_id == 'exacterp' && $minuteDifference < 40) {
			$response = app('App\Http\Controllers\ExactERP\ExactERPApiController')->RefreshToken($id);
		} elseif ($platform_id == 'logiwa' && $minuteDifference < 30) {
			$response = app('App\Http\Controllers\Logiwa\LogiwaApiController')->RefreshToken($id);
		} elseif ($platform_id == 'sdmo' && $minuteDifference < 9) {
			$response = app('App\Http\Controllers\SDMO\SDMOController')->RefreshToken($id);
		}

		return $response;
	}
}
