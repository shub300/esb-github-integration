<?php
/*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register web routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | contains the "web" middleware group. Now create something great!
    |
*/
use App\Http\Controllers\Brightpearl\BrightpearlCustomProcessController;
use App\Http\Controllers\Brightpearl\BrightPearlApiController;
use App\Http\Controllers\Brightpearl\BrightPearlApiSubController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\ExactERP\ExactERPApiController;
use App\Http\Controllers\GKTestController;
use App\Http\Controllers\HubSpot\HubSpotApiController;
use App\Http\Controllers\InventoryPlanner\InventoryPlannerApiController;
use App\Http\Controllers\Logiwa\LogiwaApiController;
use App\Http\Controllers\Snowflake\SnowflakeApiController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\Tiktok\TiktokApiController;
use App\Http\Controllers\Veracore\VeracoreApiController;
use App\Http\Controllers\Whmcs\WhmcsApiController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::group(['middleware' => 'preventBackHistory'], function () {
	Route::get('clear-cache', function () {
		$exitCode = Artisan::call('cache:clear');
		$exitCodeView = Artisan::call('view:clear');
		$exitCodeRoute = Artisan::call('route:clear');
		$exitCodeConfig = Artisan::call('config:clear');
		dd($exitCode, $exitCodeView, $exitCodeRoute, $exitCodeConfig);
	});

	// Redirect handler log
	Route::any('shipBobRedirectHandler', 'ShipBobRedirectController@ShipBobRedirectHandler');

	// Session check
	Route::any('SamlRedirectHandler', 'AuthenticationMethodController@SamlRedirectHandler');

	// Account verification staff member
	Route::get('staff-accept-invite/{token?}', 'PanelControllers\ModuleAccessController@staffAcceptInvitation');

	Route::group(['middleware' => ['company']], function () {

		Route::get('login', 'CommonController@login');
		Route::post('login', 'CommonController@login');
		Route::get('/', 'CommonController@login');
		Route::post('validate-aws-cognito-jwt-token', 'AmazonCognitoController@ajaxValidateCognitoToken');
		Route::get('jwt-token-expired', 'AmazonCognitoController@getJwtTokenExpiredView');
		/* Forget Pass form and Function */
		Route::get('forget-password', function () {
			return view('auth/forgot_password');
		});
		Route::post('password/reset', 'CommonController@requestPass');

		/* Change Pass form and Function */
		Route::get('change-password/{token?}', 'CommonController@showResetForm');
		Route::post('changePass', 'CommonController@changePass');

		/* Register Form, Verify and Function */
		Route::get('register/{platform_name?}', 'CommonController@register');
		Route::post('registerEmail', 'CommonController@registerEmail');
		Route::get('register/verify/{id?}', 'CommonController@confirm');

		// Account verification team member
		Route::get('invite/verify/{token?}', 'UserInvitationCtrl@acceptInvitation');
		Route::get('encryptText/{text}', 'CommonController@encryptText');
		Route::get('decryptText/{text}', 'CommonController@decryptText');
		Route::group(['middleware' => ['is_master_staff']], function () {
			/* For Master User */
			Route::get('launchpad', 'DashboardController@launchpad')->name('launchpad');
			/* Magic Login */
			Route::get('impersonate/{id}', 'CommonController@LoginAsUser')->name('impersonate');
			/* ----------------- */
		});

		Route::group(['middleware' => ['web', 'guest']], function () {

			Route::get('impersonate/logout/{id}', 'CommonController@SwitchBackToLaunchpad')->name('impersonate_logout');
			// User Settings

			Route::get('logout_user', 'CommonController@logoutUser');
			Route::get('user_profile', 'CommonController@userProfile');
			Route::post('update-profile', 'CommonController@UpdateProfile');
			Route::post('update-password', 'CommonController@UpdatePassword');
			Route::get('get_timezone_list', 'CommonController@getTimezoneList');
			Route::post('update_timezone', 'CommonController@UpdateTimeZone');

			Route::post('update-notification-email', 'CommonController@updateNotificationEmail');
			Route::get('get_user_timezone', 'CommonController@getUsersTimezone');
			Route::get('integrations', 'DashboardController@index')->name('home.integrations');

			// Route::get('get_integrations_list', 'DashboardController@getIntegrationsList')->name('home.getIntegrationsList');

			Route::post('connectWorkflow', 'DashboardController@connectWorkflow')->name('integration.connectWorkflow');

			Route::get('workflow/{id?}', 'WorkflowController@index');

			//route by gajendra
			Route::get('/myapps', 'MyAppsController@getMyApps');
			// Route::get('/myapps_get_integrations_list', 'MyAppsController@getMyAppsLists');
			Route::get('/check_integration_status', 'MyAppsController@checkIntegStatus');

			Route::get('integration_flow/{id}', 'MyAppsController@integrationFlow')->name('integration.integration_flow');
			Route::post('flows_list/{id}', 'MyAppsController@GetFlowList')->name('flows_list');
			Route::get('connections/{userIntegId}', 'MyAppsController@getConnections')->name('connections');
			Route::post('audit_log', 'MyAppsController@getAuditLogList');
			Route::post('check_user_workflow_status', 'MyAppsController@checkUserWorkflowStatus');
			Route::post('check-user-refresh-token-validation-expire', 'MyAppsController@checkUserRefreshTokenValidationExpire');
			Route::post('resync_platform_data', 'MyAppsController@resyncPlatformData');
			//added for get log details
			Route::post('get_log_details', 'MyAppsController@getAuditLogRowDetails');
			Route::post('updateIntegrationFlow', 'MyAppsController@updateIntegrationFlow')->name('updateIntegrationFlow');
			// Route::post('updateConnection', 'MyAppsController@updateConnection');
			Route::get('getMappingFields', 'MyAppsController@getMappingFields');
			Route::post('storeMapping', 'MyAppsController@storeMapping');
			Route::post('deletemapping', 'MyAppsController@deleteMapping');
			Route::post('delete_user_integration', 'MyAppsController@deleteUserInteg');
			Route::post('delete_mapping_file', 'MyAppsController@deleteMappingFile');
			Route::post('load_dep_drop_down_data', 'MyAppsController@loadDepDrop');
			Route::post('inactive_warehouse_mapping', 'MyAppsController@inactiveWarehouseMappingOnChangeSwitch');
			Route::post('load_ip_project_application_url', 'MyAppsController@loadIpApplicationUrl');
			//end route by gajendra


			/* Route By Awadhesh : 16-07-21*/
			//Brightpearl Oauth
			Route::get('InitiateBPAuth', 'Brightpearl\BrightPearlApiController@InitiateBPAuth')->name('brightpearl.initiate');
			Route::post('ConnectBPOauth', 'Brightpearl\BrightPearlApiController@ConnectBPOauth')->name('brightpearl.connect');
			Route::any('RedirectHandlerBp', 'Brightpearl\BrightPearlApiController@RedirectHandlerBp')->name('brightpearl.callback');
			//WooCommerce Auth
			Route::get('InitiateWCAuth', 'Woocommerce\WoocommerceApiController@InitiateWCAuth')->name('woocommerce.initiate');
			Route::post('ConnectWCAuth', 'Woocommerce\WoocommerceApiController@ConnectWCAuth')->name('woocommerce.connect');
			Route::get('testdemo', 'TestController@testdemo');
			Route::get('test_mapping_data', 'TestController@testMappingByCache');

			//Rakesh
			Route::get('Initiate3dcartAuth', 'ThreeDCart\ThreeDCartApiController@Initiate3dcartAuth')->name('Initiate3dcartAuth');
			Route::any('Connect3dcartOauth', 'ThreeDCart\ThreeDCartApiController@Connect3dcartOauth')->name('Connect3dcartOauth');
			Route::any('RedirectHandler3dcart', 'ThreeDCart\ThreeDCartApiController@RedirectHandler3dcart')->name('RedirectHandler3dcart');
			Route::get('test-3dcart', 'ThreeDCart\ThreeDCartApiController@test')->name('test-3dcart');

			Route::get('InitiateTeapplixAuth', 'Teapplix\TeapplixApiController@InitiateTeapplixAuth')->name('InitiateTeapplixAuth');
			Route::any('ConnectTeapplixAuth', 'Teapplix\TeapplixApiController@ConnectTeapplixAuth')->name('ConnectTeapplixAuth');
			Route::get('test-teapplix', 'Teapplix\TeapplixApiController@test')->name('test-teapplix');

			Route::get('InitiateShipHeroAuth', 'ShipHero\ShipHeroApiController@InitiateShipHeroAuth')->name('InitiateShipHeroAuth');
			Route::any('ConnectShipHeroAuth', 'ShipHero\ShipHeroApiController@ConnectShipHeroAuth')->name('ConnectShipHeroAuth');
			Route::get('test-shiphero', 'ShipHero\ShipHeroApiController@test')->name('test-shiphero');

			Route::get('InitiateBlueCherryAuth', 'BlueCherry\BlueCherryApiController@InitiateBlueCherryAuth')->name('InitiateBlueCherryAuth');
			Route::any('ConnectBlueCherryAuth', 'BlueCherry\BlueCherryApiController@ConnectBlueCherryAuth')->name('ConnectBlueCherryAuth');
			Route::get('test-bluecherry', 'BlueCherry\BlueCherryApiController@test')->name('test-bluecherry');

			Route::get('InitiateSDMOAuth', 'SDMO\SDMOController@InitiateSDMOAuth')->name('InitiateSDMOAuth');
			Route::any('ConnectSDMOAuth', 'SDMO\SDMOController@ConnectSDMOAuth')->name('ConnectSDMOAuth');
			Route::get('test-sdmo', 'SDMO\SDMOController@test')->name('test-sdmo');

			Route::get('InitiateShipBobAuth', 'ShipBob\ShipBobApiController@InitiateShipBobAuth')->name('InitiateShipBobAuth');
			Route::any('ConnectShipBobOauth', 'ShipBob\ShipBobApiController@ConnectShipBobOauth')->name('ConnectShipBobOauth');
			Route::any('RedirectHandlerShipBob', 'ShipBob\ShipBobApiController@RedirectHandlerShipBob')->name('RedirectHandlerShipBob');
			Route::get('test-shipbob', 'ShipBob\ShipBobApiController@test')->name('test-shipbob');

			Route::get('InitiateCetecERPAuth', 'CetecERP\CetecERPApiController@InitiateCetecERPAuth')->name('InitiateCetecERPAuth');
			Route::any('ConnectCetecERPAuth', 'CetecERP\CetecERPApiController@ConnectCetecERPAuth')->name('ConnectCetecERPAuth');
			Route::get('test-cetec', 'CetecERP\CetecERPApiController@test')->name('test-cetec');

			Route::get('InitiateMicroChipAuth', 'MicroChip\MicroChipApiController@InitiateMicroChipAuth')->name('InitiateMicroChipAuth');
			Route::any('ConnectMicroChipAuth', 'MicroChip\MicroChipApiController@ConnectMicroChipAuth')->name('ConnectMicroChipAuth');
			Route::get('test-microchip', 'MicroChip\MicroChipApiController@test')->name('test-microchip');

			Route::get('InitiateSmartsheetAuth', 'Smartsheet\SmartsheetApiController@InitiateSmartsheetAuth')->name('InitiateSmartsheetAuth');
			Route::any('ConnectSmartsheetAuth', 'Smartsheet\SmartsheetApiController@ConnectSmartsheetAuth')->name('ConnectSmartsheetAuth');
			Route::any('RedirectHandlerSmartsheet', 'Smartsheet\SmartsheetApiController@RedirectHandlerSmartsheet')->name('RedirectHandlerSmartsheet');
			Route::get('test-smartsheet', 'Smartsheet\SmartsheetApiController@test')->name('test-smartsheet');

			Route::get('InitiateSpsAuth', 'Spscommerce\SpscommerceApiController@InitiateSpsAuth');
			Route::post('InitiateSpscomOauth', 'Spscommerce\SpscommerceApiController@InitiateSpscomOauth');
			Route::any('RedirectHandlerSpsCom', 'Spscommerce\SpscommerceApiController@RedirectHandlerSpsCom');

			Route::get('InitiateIntacctAuth', 'Intacct\IntacctApiController@InitiateIntacctAuth');
			Route::post('ConnectIntacctOauth', 'Intacct\IntacctApiController@ConnectIntacctOauth');

			Route::get('InitiateBlackLineAuth', 'BlackLine\BlackLineApiController@InitiateBlackLineAuth');
			Route::post('ConnectBlacklineOauth', 'BlackLine\BlackLineApiController@ConnectBlackLineOauth');
			Route::post('ConnectBlackLineAuth', 'BlackLine\BlackLineApiController@ConnectBlackLineAuth');

			//https://esb-stag.apiworx.net/upload-blackline-customer-csv-record/228/577/intacct?isTest=1
			Route::get('upload-blackline-customer-csv-record/{user_id?}/{user_integration_id?}/{destination_platform?}', 'BlackLine\BlackLineApiController@uploadBlackLineCustomerFiles');

			//https://esb-stag.apiworx.net/upload-blackline-customer-order-record/228/577?isTest=1
			Route::get('upload-blackline-customer-order-record/{user_id?}/{user_integration_id?}', 'BlackLine\BlackLineApiController@uploadBlacklineOpenInvoiceItems');

			Route::get('InitiateKefronAuth', 'Kefron\KefronApiController@InitiateKefronAuth');
			Route::post('ConnectKefronOauth', 'Kefron\KefronApiController@ConnectKefronOauth');

			Route::get('InitiateTipaltiAuth', 'Tipalti\TipaltiApiController@InitiateTipaltiAuth');
			Route::post('ConnectTipaltiOauth', 'Tipalti\TipaltiApiController@ConnectTipaltiOauth');

			Route::get('InitiateWFAuth', 'Wayfair\WayfairApiController@InitiateWFAuth');
			Route::post('ConnectWayfairOauth', 'Wayfair\WayfairApiController@ConnectWayfairOauth');
			Route::get('WayfairGetProduct', 'Wayfair\WayfairApiController@WayfairGetProduct');
			Route::get('WayfairUpdateInventory', 'Wayfair\WayfairApiController@WayfairUpdateInventory');

			//skuvault and wayfaire routes by sb
			Route::get('InitiateSkuvaultAuth', 'Skuvault\SkuvaultApiController@initiateSkuvaultAuth')->name('InitiateSkuvaultAuth');
			//Route::post('ConnectSkuvaultAuth', 'Skuvault\SkuvaultApiController@connectSkuvaultAuth')->name('ConnectSkuvaultAuth');
			Route::post('ConnectSkuvaultAuth', 'Skuvault\SkuvaultApiController@connectSkuvaultAuth')->name('Skuvaul.ConnectAuth');
			Route::get('SkuvaultGetWarehouse', 'Skuvault\SkuvaultApiController@SkuvaultGetWarehouse')->name('SkuvaultGetWarehouse');
			Route::get('SkuvaultGetProductInventory', 'Skuvault\SkuvaultApiController@SkuvaultGetProductInventory')->name('SkuvaultGetProductInventory');


			//VikingBad routes by sb
			Route::get('InitiateVikingBadAuth', 'Vikingbad\VikingBadController@InitiateVikingBadAuth');
			Route::post('ConnectVikingBadOauth', 'Vikingbad\VikingBadController@ConnectVikingBadOauth');
			//Brandwise routes by sb
			Route::get('InitiateBrandWiseAuth', 'Brandwise\BrandwiseController@InitiateBrandWiseAuth');
			Route::post('ConnectBrandWiseOauth', 'Brandwise\BrandwiseController@ConnectBrandWiseOauth');

			//Ahlsell routes by sb
			Route::get('InitiateAhlsellAuth', 'Ahlsell\AhlsellController@InitiateAhlsellAuth');
			Route::post('ConnectAhlsellOauth', 'Ahlsell\AhlsellController@ConnectAhlsellOauth');

			// Process platform fields
			Route::get('getFields', 'FieldController@getFields');
			Route::post('getPlatformEventsAndAction', 'FieldController@getPlatformEventsAndAction');
			Route::post('GetMappingFields', 'FieldController@GetMappingFields');

			//amazon route
			Route::get('InitiateAmazonAuth', 'Amazon\AmazonApiController@initiateAmazonAuth')->name('InitiateAmazonAuth');
			Route::post('SubmitAmazonAuth', 'Amazon\AmazonApiController@submitAmazonAuth')->name('SubmitAmazonAuth');
			Route::any('ConnectAmazonAuth', 'Amazon\AmazonApiController@connectAmazonAuth')->name('ConnectAmazonAuth');
			Route::post('ConnectAmazonBasicAuth', 'Amazon\AmazonApiController@connectAmazonBasicAuth')->name('ConnectAmazonBasicAuth');
			Route::get('amazon_test', 'Amazon\AmazonApiController@test')->name('amazon_test');

			Route::get('InitiateAmazonmcfAuth', 'Amazon\AmazonMcfController@initiateAmazonAuth')->name('InitiateAmazonmcfAuth');
			Route::get('amazon-mcf-test', 'Amazon\AmazonMcfController@test')->name('amazon-mcf-test');

			//zulily route
			Route::get('InitiateZulilyAuth', 'Zulily\ZulilyApiController@initiateZulilyAuth')->name('InitiateZulilyAuth');
			Route::post('ConnectZulilytAuth', 'Zulily\ZulilyApiController@connectZulilyAuth')->name('ConnectZulilyAuth');

			//Route::get('integrations', 'IntegrationController@index');
			Route::get('connection-settings/{id}', 'ConnectionController@index');
			Route::post('getConnectedAccounts', 'ConnectionController@getConnectedAccounts')->name('integration.getConnectedAccounts');
			Route::post('getConnectedAccountInfo', 'ConnectionController@getConnectedAccountInfo')->name('integration.getConnectedAccountInfo');
			Route::post('saveConnection', 'ConnectionController@saveConnection')->name('integration.saveConnection');
			Route::post('validateAccountName', 'ConnectionController@validateAccountName');

			/** Netsuite Routes */
			Route::get('InitiateNSAuth', 'Netsuite\NetsuiteApiController@InitiateNSAuth')->name('InitiateNSAuth');
			Route::post('ConnectNetsuiteAuth', 'Netsuite\NetsuiteApiController@connectNetsuiteAuth')->name('Netsuite.ConnectAuth');
			Route::get('netsuite-test', 'Netsuite\NetsuiteApiController@test'); //test
			Route::get('netsuite-order/{orderId}', 'Netsuite\NetsuiteApiController@missingOrders'); //sync netsuit missing orders

			/** Magento Routes */
			Route::get('InitiateMGAuth', 'Magento\MagentoApiController@InitiateMGAuth')->name('InitiateMGAuth');
			Route::post('ConnectMagentoAuth', 'Magento\MagentoApiController@ConnectMagentoAuth')->name('Magento.ConnectAuth');
			Route::get('magento-test', 'Magento\MagentoApiController@test'); //t



			/** Klaviyo Routes [start] */
			Route::get('InitiateKlaviyoAuth', 'Klaviyo\KlaviyoApiController@InitiateKlaviyoAuth');
			Route::post('ConnectKlaviyo', 'Klaviyo\KlaviyoApiController@ConnectKlaviyo');
			/** Klaviyo Routes [end] */

			/** ShipRush Routes [start] */
			Route::get('InitiateShipRushAuth', 'ShipRush\ShipRushController@InitiateShipRushAuth');
			Route::post('ConnectShipRush', 'ShipRush\ShipRushController@ConnectShipRush');
			/** ShipRush Routes [end] */

			/** Korsbakken Routes */
			Route::get('InitiateKorsbakkenAuth', 'Korsbakken\KorsbakkenController@InitiateKorsbakkenAuth');
			Route::post('ConnectKorsbakken', 'Korsbakken\KorsbakkenController@ConnectKorsbakken');

			/** Peoplevox Routes */
			Route::get('InitiatePeoplevoxAuth', 'Peoplevox\PeoplevoxController@InitiatePeoplevoxAuth');
			Route::post('ConnectPeoplevox', 'Peoplevox\PeoplevoxController@ConnectPeoplevox');

			/** James and James Routes [start] */
			Route::get('InitiateJamesAuth', 'JamesAndJames\JamesApiController@InitiateJamesAuth');
			Route::post('ConnectJames', 'JamesAndJames\JamesApiController@ConnectJames');

			/** Markrt Time Routes */
			Route::get('TestMarketTime', 'MarketTime\MarketTimeApiController@TestMarketTime');
			Route::get('InitiateMarketTimeAuth', 'MarketTime\MarketTimeApiController@InitiateMarketTimeAuth');
			Route::post('ConnectMarkettimeAuth', 'MarketTime\MarketTimeApiController@ConnectMarkettimeAuth');

			/** Heidenreich Routes */
			Route::get('InitiateHeidenreichAuth', 'Heidenreich\HeidenreichController@InitiateHeidenreichAuth');
			Route::post('connectHeidenreichAuth', 'Heidenreich\HeidenreichController@ConnectHeidenreichOauth');

			/** Jasci Routes */
			Route::get('InitiateJasciAuth', 'Jasci\JasciController@InitiateJasciAuth');
			Route::post('connectJasciAuth', 'Jasci\JasciController@ConnectJasciOauth');
			Route::get('test_jasci', 'Jasci\JasciController@test');



			//Brodrene Routes
			// Route::any('brodreneRedirectHandler', 'Brodrene\BrodreneController@redirectHandler');
			Route::get('InitiateBrodreneAuth', 'Brodrene\BrodreneController@InitiateBrodreneAuth')->name('brodrene.initiate');
			Route::post('connectBrodreneAuth', 'Brodrene\BrodreneController@ConnectBrodreneOauth')->name('brodrene.connect');
			//Redirect Handler Brodrene
			Route::any('brodreneRedirectHandler', 'Brodrene\BrodreneController@redirectHandler')->name('brodrene.callback');
			// Route::get('test_encrypt_decript','Brodrene\BrodreneController@testEncrpt_decrypt');

			//Squarespace Routes
			Route::get('InitiateSQSAuth', 'Squarespace\SquarespaceController@InitiateSquarespaceAuth')->name('squarespace.initiate');
			Route::any('connectSquarespaceAuth', 'Squarespace\SquarespaceController@ConnectSquarespaceOauth')->name('squarespace.connect');
			Route::any('squarespaceRedirectHandler', 'Squarespace\SquarespaceController@redirectHandler')->name('squarespace.callback');
			//Squarespace test route
			Route::get('test_squarespace', 'Squarespace\SquarespaceController@test_squarespace');

			//trailsend Routes
			Route::get('InitiateTrailsEndAuth', 'Trailsend\TrailsendController@InitiateTrailsendAuth');
			Route::post('connectTrailsendAuth', 'Trailsend\TrailsendController@connectTrailsendAuth');
			Route::get('test_trailsend', 'Trailsend\TrailsendController@testTrailsend');


			// Module Access Routes
			Route::get('manage-staff', 'PanelControllers\ModuleAccessController@index')->name('staff.list');
			Route::post('get_staff_members', 'PanelControllers\ModuleAccessController@getStaffMembers');
			Route::post('delete_staff_member', 'PanelControllers\ModuleAccessController@deleteStaffMember');
			Route::get('invite-staff-member', 'PanelControllers\ModuleAccessController@inviteStaffMember');
			Route::post('send_invitation_mail', 'PanelControllers\ModuleAccessController@sendInvitationMail');
			Route::get('update-staff-member/{id}', 'PanelControllers\ModuleAccessController@updateStaffMember');
			Route::post('staff_update_rights', 'PanelControllers\ModuleAccessController@staffUpdateRights');

			Route::get('step2', 'IntegrationController@integrtaionStep2');
			Route::get('step3', 'IntegrationController@integrtaionStep3');
			Route::get('step4', 'IntegrationController@integrtaionStep4');
			Route::get('account', 'IntegrationController@account');
			Route::get('table', 'IntegrationController@table');
			Route::get('mapping', 'IntegrationController@mapping');
			Route::get('test12', 'TestApiController@test');
			Route::get('encryptText/{text}', 'CommonController@encryptText');
			Route::get('decryptText/{text}', 'CommonController@decryptText');
			Route::post('Disconnect', 'IntegrationController@Disconnect');

			// Google Sheet Auth
			Route::get('InitiateGoogleAuthForSpreadsheet', 'Google\GoogleSpreadsheetController@InitiateGoogleAuthForSpreadsheet');
			Route::get('google/authback', 'Google\GoogleSpreadsheetController@checkForAuthCode');
			Route::get('google/authback/token', 'Google\GoogleSpreadsheetController@getNewRefreshTokenForTheCurrentUser');

			/* ---Start 3PL Routes--- */
			Route::get('InitiateThreePLAuth', 'ThreePL\ThreePLApiController@InitiateThreePLAuth')->name('3pl.initiate');
			Route::post('ConnectThreePLAuth', 'ThreePL\ThreePLApiController@ConnectThreePLAuth')->name('3pl.connect');
			/* ---End 3PL Routes--- */

			/* ---Start Extensiv Billing Manager Routes--- */
			Route::get('InitiateExtensivBillingManagerAuth', 'ExtensivBillingManager\ExtensivBillingManagerApiController@InitiateExtensivBillingManagerAuth')->name('extensivbillingmanager.initiate');
			Route::post('ConnectExtensivBillingManagerAccount', 'ExtensivBillingManager\ExtensivBillingManagerApiController@ConnectExtensivBillingManagerAccount')->name('extensivbillingmanager.connect');
			/* ---End Extensiv Billing Manager Routes--- */

			/* ---Start CS Cart Routes--- */
			Route::get('InitiateCSCartAuth', 'CSCart\CSCartApiController@InitiateCSCartAuth')
				->name('cscart.initiate');
			Route::post('ConnectCSCartAuth', 'CSCart\CSCartApiController@ConnectCSCartAuth')->name('cscart.connect');
			Route::get('test-cscart', 'CSCart\CSCartApiController@test')->name('test-cscart');
			/* ---End CS Cart Routes--- */
			// Re:amaze Routes::START
			Route::get('InitiateReamazeAuth', 'Reamaze\ReamazeApiController@InitiateReamazeAuth')->name('reamaze.initiate');
			Route::any('ConnectReamazeAuth', 'Reamaze\ReamazeApiController@ConnectReamazeAuth')->name('reamaze.connect');
			// Re:amaze Routes::END
			// -- Shiphawk Routes::START
			Route::get('InitiateShiphawkAuth', 'ShipHawk\ShipHawkController@InitiateShiphawkAuth')->name('shiphawk.initiate');
			Route::any('ConnectShiphawkAuth', 'ShipHawk\ShipHawkController@ConnectShiphawkAuth')->name('shiphawk.connect');
			// -- Shiphawk Routes::END

			//--Shipstation Routes::START
			Route::get('InitiateShipstationAuth', 'Shipstation\ShipstationController@InitiateShipstationAuth')->name('shipstation.initiate');
			Route::any('ConnectShipstationAuth', 'Shipstation\ShipstationController@ConnectShipstationAuth')->name('shipstation.connect');
			Route::get('shipstation_test', 'Shipstation\ShipstationController@test')->name('shipstation_test');
			// -- Shipstation Routes::END


			// -- UPS Routes::START
			Route::get('InitiateUPSAuth', 'UPS\UPSController@InitiateUPSAuth')->name('ups.initiate');
			Route::any('ConnectUPSAuth', 'UPS\UPSController@ConnectUPSAuth')->name('ups.connect');
			// -- UPS Routes::END
			// -- Bigcommerce Routes::START
			Route::get('InitiateBigCommerceAuth', 'Bigcommerce\BigcommerceController@InitiateBigCommerceAuth')->name('bigcommerce.initiate');
			Route::any('ConnectBigCommerceAuth', 'Bigcommerce\BigcommerceController@ConnectBigCommerceAuth')->name('bigcommerce.connect');
			// -- Bigcommerce Routes::END

			/* ---Start Infoplus Routes --- */
			Route::get('InitiateInfoplusAuth', 'Infoplus\InfoplusApiController@InitiateInfoplusAuth')->name('infoplus.initiate');
			Route::post('ConnectInfoplusAuth', 'Infoplus\InfoplusApiController@ConnectInfoplusAuth')->name('infoplus.connect');
			Route::get('test_infoplus', 'Infoplus\InfoplusApiController@test');
			/* ---End Infoplus Routes--- */

			/* ---Start GunBroker Routes --- */
			Route::get('InitiateGunBrokerAuth', 'GunBroker\GunBrokerController@InitiateGunBrokerAuth')->name('gunbroker.initiate');
			Route::post('ConnectGunBroker', 'GunBroker\GunBrokerController@ConnectGunBroker')->name('gunbroker.connect');
			/* ---End GunBroker Routes--- */

			/* ---Start TaxJar Routes --- */
			Route::get('InitiateTaxJarAuth', 'TaxJar\TaxJarController@InitiateTaxJarAuth')->name('taxjar.initiate');
			Route::post('ConnectTaxJar', 'TaxJar\TaxJarController@ConnectTaxJar')->name('taxjar.connect');
			/* ---End TaxJar Routes--- */

			/**
			 * Tmp Function
			 */
			Route::get('bp-create-po-goods-note', [BrightPearlApiSubController::class, 'CreatePOGoodsInNote'])->name('bp.cpogn');
			Route::get('bp-sync-tracking-info/{user?}/{integration?}/{prule?}/{urule?}/{source?}', [BrightPearlApiController::class, 'SyncTrackingInformation'])->name('bp.cpogn');
			Route::get('bp-get-shipment/{user?}/{integration?}', [BrightPearlApiController::class, 'GetShipment'])->name('bp.getship');

			/** QuickBooks Routes */
			Route::get('InitiateQboAuth', 'QuickBooks\QuickBooksApiController@InitiateQboAuth')->name('quickbooks.initiate');
			Route::post('ConnectQuickBooksOauth', 'QuickBooks\QuickBooksApiController@ConnectQuickBooksOauth')->name('quickbooks.connect');
			Route::any('RedirectHandlerQuickBooks', 'QuickBooks\QuickBooksApiController@RedirectHandlerQuickBooks')->name('quickbooks.callback');
			Route::get('testqb', 'QuickBooks\QuickBooksApiController@test')->name('quickbooks.test');

			/** Skubana Routes */
			Route::get('InitiateSkubanaAuth', 'Skubana\SkubanaApiController@InitiateSkubanaAuth')->name('skubana.initiate');
			Route::any('ConnectSkubanaAuth', 'Skubana\SkubanaApiController@ConnectSkubanaAuth')->name('skubana.connect');
			Route::any('RedirectHandlerOM', 'Skubana\SkubanaApiController@RedirectHandlerOM')->name('skubana.callback');
			Route::get('testom', 'Skubana\SkubanaApiController@test')->name('skubana.test');


			//VikingBad routes by sb
			Route::get('InitiateVikingBadAuth', 'Vikingbad\VikingBadController@InitiateVikingBadAuth');
			Route::post('ConnectVikingBadOauth', 'Vikingbad\VikingBadController@ConnectVikingBadOauth');
			//Brandwise routes by sb
			Route::get('InitiateBrandWiseAuth', 'Brandwise\BrandwiseController@InitiateBrandWiseAuth');
			Route::post('ConnectBrandWiseOauth', 'Brandwise\BrandwiseController@ConnectBrandWiseOauth');

			//Ahlsell routes by sb
			Route::get('InitiateAhlsellAuth', 'Ahlsell\AhlsellController@InitiateAhlsellAuth');
			Route::post('ConnectAhlsellOauth', 'Ahlsell\AhlsellController@ConnectAhlsellOauth');

			// Process platform fields
			Route::get('getFields', 'FieldController@getFields');
			Route::post('getPlatformEventsAndAction', 'FieldController@getPlatformEventsAndAction');
			Route::post('GetMappingFields', 'FieldController@GetMappingFields');

			//amazon route
			Route::get('InitiateAmazonAuth', 'Amazon\AmazonApiController@initiateAmazonAuth')->name('InitiateAmazonAuth');
			Route::post('SubmitAmazonAuth', 'Amazon\AmazonApiController@submitAmazonAuth')->name('SubmitAmazonAuth');
			Route::any('ConnectAmazonAuth', 'Amazon\AmazonApiController@connectAmazonAuth')->name('ConnectAmazonAuth');
			Route::post('ConnectAmazonBasicAuth', 'Amazon\AmazonApiController@connectAmazonBasicAuth')->name('ConnectAmazonBasicAuth');
			Route::get('amazon_test', 'Amazon\AmazonApiController@test')->name('amazon_test');

			Route::get('InitiateAmazonmcfAuth', 'Amazon\AmazonMcfController@initiateAmazonAuth')->name('InitiateAmazonmcfAuth');
			Route::get('amazon-mcf-test', 'Amazon\AmazonMcfController@test')->name('amazon-mcf-test');

			//zulily route
			Route::get('InitiateZulilyAuth', 'Zulily\ZulilyApiController@initiateZulilyAuth')->name('InitiateZulilyAuth');
			Route::post('ConnectZulilytAuth', 'Zulily\ZulilyApiController@connectZulilyAuth')->name('ConnectZulilyAuth');

			//Route::get('integrations', 'IntegrationController@index');
			Route::get('connection-settings/{id}', 'ConnectionController@index');
			Route::post('getConnectedAccounts', 'ConnectionController@getConnectedAccounts')->name('integration.getConnectedAccounts');
			Route::post('getConnectedAccountInfo', 'ConnectionController@getConnectedAccountInfo')->name('integration.getConnectedAccountInfo');
			Route::post('saveConnection', 'ConnectionController@saveConnection')->name('integration.saveConnection');
			Route::post('validateAccountName', 'ConnectionController@validateAccountName');


			/** Magento Routes */
			Route::get('InitiateMGAuth', 'Magento\MagentoApiController@InitiateMGAuth')->name('InitiateMGAuth');
			Route::post('ConnectMagentoAuth', 'Magento\MagentoApiController@ConnectMagentoAuth')->name('Magento.ConnectAuth');
			Route::get('magento-test', 'Magento\MagentoApiController@test'); //t



			/** Klaviyo Routes [start] */
			Route::get('InitiateKlaviyoAuth', 'Klaviyo\KlaviyoApiController@InitiateKlaviyoAuth');
			Route::post('ConnectKlaviyo', 'Klaviyo\KlaviyoApiController@ConnectKlaviyo');
			/** Klaviyo Routes [end] */

			/** ShipRush Routes [start] */
			Route::get('InitiateShipRushAuth', 'ShipRush\ShipRushController@InitiateShipRushAuth');
			Route::post('ConnectShipRush', 'ShipRush\ShipRushController@ConnectShipRush');
			/** ShipRush Routes [end] */

			/** Korsbakken Routes */
			Route::get('InitiateKorsbakkenAuth', 'Korsbakken\KorsbakkenController@InitiateKorsbakkenAuth');
			Route::post('ConnectKorsbakken', 'Korsbakken\KorsbakkenController@ConnectKorsbakken');

			/** Markrt Time Routes */
			Route::get('TestMarketTime', 'MarketTime\MarketTimeApiController@TestMarketTime');
			Route::get('InitiateMarketTimeAuth', 'MarketTime\MarketTimeApiController@InitiateMarketTimeAuth');
			Route::post('ConnectMarkettimeAuth', 'MarketTime\MarketTimeApiController@ConnectMarkettimeAuth');

			/** Heidenreich Routes */
			Route::get('InitiateHeidenreichAuth', 'Heidenreich\HeidenreichController@InitiateHeidenreichAuth');
			Route::post('connectHeidenreichAuth', 'Heidenreich\HeidenreichController@ConnectHeidenreichOauth');

			/** Jasci Routes */
			Route::get('InitiateJasciAuth', 'Jasci\JasciController@InitiateJasciAuth');
			Route::post('connectJasciAuth', 'Jasci\JasciController@ConnectJasciOauth');
			Route::get('test_jasci', 'Jasci\JasciController@test');



			//Brodrene Routes
			// Route::any('brodreneRedirectHandler', 'Brodrene\BrodreneController@redirectHandler');
			Route::get('InitiateBrodreneAuth', 'Brodrene\BrodreneController@InitiateBrodreneAuth')->name('brodrene.initiate');
			Route::post('connectBrodreneAuth', 'Brodrene\BrodreneController@ConnectBrodreneOauth')->name('brodrene.connect');
			//Redirect Handler Brodrene
			Route::any('brodreneRedirectHandler', 'Brodrene\BrodreneController@redirectHandler')->name('brodrene.callback');
			// Route::get('test_encrypt_decript','Brodrene\BrodreneController@testEncrpt_decrypt');

			//Squarespace Routes
			Route::get('InitiateSQSAuth', 'Squarespace\SquarespaceController@InitiateSquarespaceAuth')->name('squarespace.initiate');
			Route::any('connectSquarespaceAuth', 'Squarespace\SquarespaceController@ConnectSquarespaceOauth')->name('squarespace.connect');
			Route::any('squarespaceRedirectHandler', 'Squarespace\SquarespaceController@redirectHandler')->name('squarespace.callback');
			//Squarespace test route
			Route::get('test_squarespace', 'Squarespace\SquarespaceController@test_squarespace');

			//trailsend Routes
			Route::get('InitiateTrailsEndAuth', 'Trailsend\TrailsendController@InitiateTrailsendAuth');
			Route::post('connectTrailsendAuth', 'Trailsend\TrailsendController@connectTrailsendAuth');
			Route::get('test_trailsend', 'Trailsend\TrailsendController@testTrailsend');


			// Module Access Routes
			Route::get('manage-staff', 'PanelControllers\ModuleAccessController@index')->name('staff.list');
			Route::post('get_staff_members', 'PanelControllers\ModuleAccessController@getStaffMembers');
			Route::post('delete_staff_member', 'PanelControllers\ModuleAccessController@deleteStaffMember');
			Route::get('invite-staff-member', 'PanelControllers\ModuleAccessController@inviteStaffMember');
			Route::post('send_invitation_mail', 'PanelControllers\ModuleAccessController@sendInvitationMail');
			Route::get('update-staff-member/{id}', 'PanelControllers\ModuleAccessController@updateStaffMember');
			Route::post('staff_update_rights', 'PanelControllers\ModuleAccessController@staffUpdateRights');

			Route::get('step2', 'IntegrationController@integrtaionStep2');
			Route::get('step3', 'IntegrationController@integrtaionStep3');
			Route::get('step4', 'IntegrationController@integrtaionStep4');
			Route::get('account', 'IntegrationController@account');
			Route::get('table', 'IntegrationController@table');
			Route::get('mapping', 'IntegrationController@mapping');
			Route::get('test12', 'TestApiController@test');
			Route::get('encryptText/{text}', 'CommonController@encryptText');
			Route::get('decryptText/{text}', 'CommonController@decryptText');
			Route::post('Disconnect', 'IntegrationController@Disconnect');

			// Google Sheet Auth
			Route::get('InitiateGoogleAuthForSpreadsheet', 'Google\GoogleSpreadsheetController@InitiateGoogleAuthForSpreadsheet');
			Route::get('google/authback', 'Google\GoogleSpreadsheetController@checkForAuthCode');
			Route::get('google/authback/token', 'Google\GoogleSpreadsheetController@getNewRefreshTokenForTheCurrentUser');
			/* ---Start 3PL Routes--- */
			Route::get('InitiateThreePLAuth', 'ThreePL\ThreePLApiController@InitiateThreePLAuth')
				->name('3pl.initiate');
			Route::post('ConnectThreePLAuth', 'ThreePL\ThreePLApiController@ConnectThreePLAuth')->name('3pl.connect');
			/* ---End 3PL Routes--- */
			/* ---Start CS Cart Routes--- */
			Route::get('InitiateCSCartAuth', 'CSCart\CSCartApiController@InitiateCSCartAuth')
				->name('cscart.initiate');
			Route::post('ConnectCSCartAuth', 'CSCart\CSCartApiController@ConnectCSCartAuth')->name('cscart.connect');
			Route::get('test-cscart', 'CSCart\CSCartApiController@test')->name('test-cscart');
			/* ---End CS Cart Routes--- */
			// Re:amaze Routes::START
			Route::get('InitiateReamazeAuth', 'Reamaze\ReamazeApiController@InitiateReamazeAuth')->name('reamaze.initiate');
			Route::any('ConnectReamazeAuth', 'Reamaze\ReamazeApiController@ConnectReamazeAuth')->name('reamaze.connect');
			// Re:amaze Routes::END
			// -- Shiphawk Routes::START
			Route::get('InitiateShiphawkAuth', 'ShipHawk\ShipHawkController@InitiateShiphawkAuth')->name('shiphawk.initiate');
			Route::any('ConnectShiphawkAuth', 'ShipHawk\ShipHawkController@ConnectShiphawkAuth')->name('shiphawk.connect');
			// -- Shiphawk Routes::END

			//--Shipstation Routes::START
			Route::get('InitiateShipstationAuth', 'Shipstation\ShipstationController@InitiateShipstationAuth')->name('shipstation.initiate');
			Route::any('ConnectShipstationAuth', 'Shipstation\ShipstationController@ConnectShipstationAuth')->name('shipstation.connect');
			Route::get('shipstation_test', 'Shipstation\ShipstationController@test')->name('shipstation_test');
			// -- Shipstation Routes::END


			// -- UPS Routes::START
			Route::get('InitiateUPSAuth', 'UPS\UPSController@InitiateUPSAuth')->name('ups.initiate');
			Route::any('ConnectUPSAuth', 'UPS\UPSController@ConnectUPSAuth')->name('ups.connect');
			// -- UPS Routes::END
			// -- Bigcommerce Routes::START
			Route::get('InitiateBigCommerceAuth', 'Bigcommerce\BigcommerceController@InitiateBigCommerceAuth')->name('bigcommerce.initiate');
			Route::any('ConnectBigCommerceAuth', 'Bigcommerce\BigcommerceController@ConnectBigCommerceAuth')->name('bigcommerce.connect');
			// -- Bigcommerce Routes::END

			/* ---Start Infoplus Routes --- */
			Route::get('InitiateInfoplusAuth', 'Infoplus\InfoplusApiController@InitiateInfoplusAuth')->name('infoplus.initiate');
			Route::post('ConnectInfoplusAuth', 'Infoplus\InfoplusApiController@ConnectInfoplusAuth')->name('infoplus.connect');
			/* ---End Infoplus Routes--- */

			/* ---Start GunBroker Routes --- */
			Route::get('InitiateGunBrokerAuth', 'GunBroker\GunBrokerController@InitiateGunBrokerAuth')->name('gunbroker.initiate');
			Route::post('ConnectGunBroker', 'GunBroker\GunBrokerController@ConnectGunBroker')->name('gunbroker.connect');
			/* ---End GunBroker Routes--- */

			/* ---Start TaxJar Routes --- */
			Route::get('InitiateTaxJarAuth', 'TaxJar\TaxJarController@InitiateTaxJarAuth')->name('taxjar.initiate');
			Route::post('ConnectTaxJar', 'TaxJar\TaxJarController@ConnectTaxJar')->name('taxjar.connect');
			/* ---End TaxJar Routes--- */

			/* ---Start WHMCS Routes --- */
			Route::get('InitiateWhmCsAuth', [WhmcsApiController::class, 'InitiateWhmCsAuth'])->name('whmcs.initiate');
			Route::post('ConnectWhmCs', [WhmcsApiController::class, 'ConnectWhmCs'])->name('whmcs.connect');
			Route::get('whmcs-webhook-ticket-detail/{user_id?}/{ticket_id?}/{user_integration_id?}/{user_workflow_rule_id?}/{source_platform_id?}', [WhmcsApiController::class, 'getTicketDetails']);

			/**
			 * Temp
			 */
			Route::get('get-all-whmcs-ticket/{user_id?}/{user_integration_id?}', [WhmcsApiController::class, 'getTickets'])->name('whmcs.get');
			Route::get('get-all-whmcs-ticket-status/{user_id?}/{user_integration_id?}', [WhmcsApiController::class, 'getTicketStatus'])->name('whmcs.get-ticket-status');
			Route::get('get-all-whmcs-ticket-department/{user_id?}/{user_integration_id?}', [WhmcsApiController::class, 'getTicketDepartment'])->name('whmcs.get-ticket-department');
			Route::get('get-whmcs-ticket-detail/{user_id?}/{ticket_id}', [WhmcsApiController::class, 'getTicketDetails'])->name('whmcs.details');
			Route::get('update-whmcs-ticket-detail/{user_id?}/{ticket_id}', [WhmcsApiController::class, 'updateTicket'])->name('whmcs.update');
			Route::get('create-whmcs-ticket/{user_id?}', [WhmcsApiController::class, 'createTicket'])->name('whmcs.create');
			Route::get('reply-whmcs-ticket/{user_id?}/{user_integration_id?}', [WhmcsApiController::class, 'replyTicket'])->name('whmcs.reply');
			Route::get('test-whmcs', [WhmcsApiController::class, 'testWHMCS'])->name('whmcs.test');

			/* ---End WHMCS Routes--- */

			/* ---Start HubSpot Routes --- */
			Route::get('InitiateHubSpotAuth', [HubSpotApiController::class, 'InitiateHubSpotAuth'])->name('hubspot.initiate');
			Route::post('ConnectHubSpot', [HubSpotApiController::class, 'ConnectHubSpot'])->name('hubspot.connect');
			Route::get('RedirectHandlerHubSpot', [HubSpotApiController::class, 'RedirectHandlerHubSpot'])->name('hubspot.redirect-hubspot');
			Route::get('hubspot-refresh-token/{id}', [HubSpotApiController::class, 'RefreshToken'])->name('hubspot.refreshToken'); //http://integration.esb/hubspot-refresh-token/1034

			/**
			 * Temp
			 */
			Route::get('get-all-hubspot-ticket/{user_id?}/{user_integration_id?}', [HubSpotApiController::class, 'getTickets']);
			Route::get('get-all-hubspot-ticket-status/{user_id?}/{user_integration_id?}', [HubSpotApiController::class, 'getTicketStatus']);
			Route::get('get-hubspot-ticket-detail/{user_id?}/{ticket_id}', [HubSpotApiController::class, 'getTicketDetails']);
			Route::get('update-hubspot-ticket-detail/{user_id?}/{ticket_id}', [HubSpotApiController::class, 'updateTicket']);
			Route::get('create-hubspot-ticket/{user_id?}', [HubSpotApiController::class, 'createTicket']);
			Route::get('create-hubspot-attachment/{user_id?}', [HubSpotApiController::class, 'CreateHubSpotAttachment']);
			Route::get('reply-hubspot-ticket', [HubSpotApiController::class, 'replyTicket']);
			Route::get('check-hubspot-customer', [HubSpotApiController::class, 'checkOrCreateCustomer']);
			Route::get('get-owners/{user_id?}/{user_integration_id?}', [HubSpotApiController::class, 'getOwners']);
			Route::get('post-owners/{user_id?}/{user_integration_id?}', [HubSpotApiController::class, 'submitOwners']);
			/* ---End HubSpot Routes--- */

			/* ---Start Snowflake Routes --- */
			Route::get('InitiateSnowflakeAuth', [SnowflakeApiController::class, 'InitiateSnowflakeAuth'])->name('snowflake.initiate');
			Route::post('ConnectSnowflake', [SnowflakeApiController::class, 'ConnectSnowflake'])->name('snowflake.connect');
			Route::get('RedirectHandlerSnowflake', [SnowflakeApiController::class, 'RedirectHandlerSnowflake'])->name('snowflake.redirect-snowflake'); //http://integration.esb/RedirectHandlerSnowflake
			Route::get('snowflake-refresh-token/{id}', [SnowflakeApiController::class, 'RefreshToken'])->name('snowflake.refreshToken'); //http://integration.esb/snowflake-refresh-token/?
			Route::get('snowflake-re-auth-token/{id}', [SnowflakeApiController::class, 'ReConnectSnowflake'])->name('snowflake.reAuthToken'); //http://integration.esb/snowflake-re-auth-token/?

			/**
			 * Temp Function
			 */
			Route::get('get-snowflake-vendors/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'getVendors']);
			Route::get('get-snowflake-po/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'GetPurchaseOrders']);
			Route::get('get-snowflake-to/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'GetOrders']);
			Route::get('create-snowflake-statement/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'createStatements']);
			Route::get('create-snowflake-product/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'createUpdateProducts']);
			Route::get('update-snowflake-product-inventory/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'updateProductInventory']);
			Route::get('create-snowflake-po-receipt/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'createPurchaseOrderReceipt']);
			Route::get('convert-snowflake-token', [SnowflakeApiController::class, 'convertToken']);
			Route::get('create-snowflake-sales-order/{user_id?}/{user_integration_id?}', [SnowflakeApiController::class, 'createSalesOrder']);
			Route::get('get-snowflake-warehouse/{user_id?}/{user_integration_id?}/{source_id?}/{source_name?}', [SnowflakeApiController::class, 'getWarehouse']);
			Route::get('get-snowflake-warehouse-test/{user_id?}/{user_integration_id?}/{source_id?}/{source_name?}', [SnowflakeApiController::class, 'getWareHouseLists']);
			Route::get('snowflake-test', [SnowflakeApiController::class, 'test']);
			Route::get('snowflake-test-order', [GKTestController::class, 'GetOrders']);
			/* ---End Snowflake Routes--- */

			/* ---Start Veracore Routes --- */
			Route::get('InitiateVeracoreAuth', [VeracoreApiController::class, 'InitiateVeracoreAuth'])->name('veracore.initiate');
			Route::post('ConnectVeracoreAuth', [VeracoreApiController::class, 'ConnectVeracoreAuth'])->name('Veracore.connect');

			/**
			 * Temp Function
			 */
			Route::get('create-veracore-po/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'createPurchaseOrder']);
			Route::get('create-veracore-po-step1/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'createExpectedArriavalReportStep1']);
			Route::get('create-veracore-po-step2/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'createExpectedArriavalReportStep2']);
			Route::get('create-veracore-po-step3/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'createExpectedArriavalReportStep3']);
			Route::get('get-veracore-warehouse/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'getVeracoreWarehouse']);
			Route::get('create-veracore-product-step-1/{user_id?}/{user_integration_id?}/{init?}', [VeracoreApiController::class, 'createValuationReport']);
			Route::get('create-veracore-product-step-2/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'getValuationProducts']);
			Route::get('update-veracore-product-as-sku/{user_id?}/{user_integration_id?}', [VeracoreApiController::class, 'updateProducts']);
			Route::get('remove-veracore-product', [VeracoreApiController::class, 'deleteProductEntry']);
			Route::get('remove-veracore-url', [VeracoreApiController::class, 'deletePlatformUrl']);
			/* ---End Veracore Routes--- */

			/* ---Start Tiktok Routes --- */
			Route::get('InitiateTiktokAuth', [TiktokApiController::class, 'InitiateTiktokAuth'])->name('tiktok.initiate');
			Route::post('ConnectTiktokAuth', [TiktokApiController::class, 'ConnectTiktokAuth'])->name('tiktok.connect');
			Route::get('RedirectHandlerTiktok', [TiktokApiController::class, 'RedirectHandlerTiktok'])->name('tiktok.redirect-tiktok');
			Route::get('tiktok-refresh-token/{id}', [TiktokApiController::class, 'RefreshToken'])->name('tiktok.refreshToken'); //https://esb-stag.apiworx.net/tiktok-refresh-token/1082

			/* ---Start ExactERP Routes --- */
			Route::get('InitiateExactERPAuth', [ExactERPApiController::class, 'InitiateExactERPAuth'])->name('exacterp.initiate');
			Route::post('ConnectExactERP', [ExactERPApiController::class, 'ConnectExactERP'])->name('exacterp.connect');
			Route::get('RedirectHandlerExactERP', [ExactERPApiController::class, 'RedirectHandlerExactERP'])->name('exacterp.redirect-ExactERP'); //http://integration.esb/RedirectHandlerExactERP
			Route::get('exacterp-refresh-token/{id}', [ExactERPApiController::class, 'RefreshToken'])->name('exacterp.refreshToken'); //http://integration.esb/snowflake-refresh-token/?

			/**
			 * Temp Function remove after live working
			 */
			Route::get('get-exacterp-division/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'getLoginDivision']);
			Route::get('get-exacterp-warehouse/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'getWareHouseLists']);
			Route::get('get-exacterp-supplier/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'getSuppliers']);
			Route::get('get-exacterp-sales-order/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'gatSalesOrder']);
			Route::get('get-exacterp-product/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'getProducts']);
			Route::get('post-tor/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'postTransferOrder']);
			Route::get('get-exacterp-tor/{user_id?}/{user_integration_id?}', [ExactERPApiController::class, 'getTransferOrderReceipt']);
			/* ---End Veracore Routes--- */

			/* ---Start Logiwa Routes --- */
			Route::get('InitiateLogiwaAuth', [LogiwaApiController::class, 'InitiateLogiwaAuth'])->name('logiwa.initiate');
			Route::post('ConnectLogiwa', [LogiwaApiController::class, 'ConnectLogiwa'])->name('logiwa.connect');

			/**
			 * Temp Function remove after live working
			 */
			Route::get('get-logiwa-product/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getProducts']);
			Route::get('get-logiwa-product-inventory/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getProductInventories']);
			Route::get('get-logiwa-sale-order/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getSalesOrders']);
			Route::get('get-logiwa-warehouse/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getWareHouseLists']);
			Route::get('get-logiwa-order-status/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getSalesOrderStatuses']);
			Route::get('update-logiwa-order-product-id', [LogiwaApiController::class, 'updateOrderLineProductId']);
			Route::get('get-logiwa-client-account/{user_id?}/{user_integration_id?}', [LogiwaApiController::class, 'getClientAccounts']);

			/* ---Start Inventory Planner Routes --- */
			Route::get('InitInventoryPlannerAuth', [InventoryPlannerApiController::class, 'InitiateInventoryPlannerAuth'])->name('ip.initiate');
			Route::post('ConnectInventoryPlanner', [InventoryPlannerApiController::class, 'ConnectInventoryPlanner'])->name('ip.connect');

			/**
			 * Temp Function remove after live working
			 */
			Route::get('get-ip-sale-order/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'GetOrders']);
			Route::get('get-ip-warehouse/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'getWareHouseLists']);
			Route::get('create-ip-warehouse/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'createWarehouse']);
			Route::get('get-ip-vendors/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'GetVendors']);
			Route::get('create-ip-vendors/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'CreateVendor']);
			Route::get('create-ip-products/{user_id?}/{user_integration_id?}', [InventoryPlannerApiController::class, 'CreateProducts']);
			Route::get('update-ip-product', [InventoryPlannerApiController::class, 'updateExcelColumn']);

			Route::get('get_user_log', 'LogHistoryController@index');
			Route::post('get_user_log', 'LogHistoryController@index');

		});
	});
});

Route::get('set-customer-ready-state-sql', function () {
	DB::table('platform_customer')
		->where('user_id', 228)  // find your user by their email
		//->limit(1)  // optional - to ensure only one record is updated.
		->update( ['sync_status' => 'Ready'] );  // update the record in the DB.
});

Route::get('download-image', function () {
	$url = "http://www.google.co.in/intl/en_com/images/srpr/logo1w.png";
	$contents = file_get_contents($url);
	$name = substr($url, strrpos($url, '/') + 1);
	// Storage::put($name, $contents);
	Storage::disk('local')->put('storage/logo1w.png', $contents);
});

/**
 * Tmp Function
 */
Route::get('wayfair-create-ship-label', [TestController::class, 'createShipmentLabel']);
Route::get('get-workflow-events', [TestController::class, 'getWorkflowEvents']);
Route::get('get_account_token_refresh_details/{filter?}', 'WorkflowController@getAccountTokenRefreshDetails');
Route::get('get_user_log', 'LogHistoryController@index');
Route::get('get-sf-refresh-notification/{id?}/{user_integration_id?}', [ CommonController::class, 'sendRefreshTokenReSyncNotification' ]);
Route::get('ip-test', [InventoryPlannerApiController::class, 'test']);