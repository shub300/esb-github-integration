<?php

use App\Http\Controllers\HubSpot\HubSpotApiController;
use App\Http\Controllers\Whmcs\WhmcsApiController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::any('/test_sps', 'Spscommerce\SpscommerceApiController@test_sps')->name('test_sps');
Route::any('/test_wf', 'WorkflowController@test_wf')->name('test_wf');
Route::any('/test_intacct', 'Intacct\IntacctApiController@test_intacct')->name('test_intacct');
Route::any('/test_kefron', 'Kefron\KefronApiController@test_kefron')->name('test_kefron');
Route::any('/test_tipalti', 'Tipalti\TipaltiApiController@test_tipalti')->name('test_tipalti');

Route::any('/sb_test', 'Skuvault\SkuvaultApiController@test');
Route::get('test', 'Wayfair\WayfairApiController@test');
Route::get('TestMarketTime', 'MarketTime\MarketTimeApiController@TestMarketTime');


Route::middleware('log_api_requests')->group(function () {

    Route::any('/kefron_proxy_get_po_invoice_files', 'Kefron\KefronApiController@KefronProxyGetPOInvoiceFiles')->name('kefron_proxy_get_po_invoice_files');
    Route::any('/kefron_proxy_get_non_po_invoice_files', 'Kefron\KefronApiController@KefronProxyGetNonPOInvoiceFiles')->name('kefron_proxy_get_non_po_invoice_files');
    Route::any('/tipalti_proxy_get_po_invoice_files', 'Tipalti\TipaltiApiController@TipaltiProxyGetPOInvoiceFiles')->name('tipalti_proxy_get_po_invoice_files');
    Route::any('/tipalti_proxy_get_po_payment_files', 'Tipalti\TipaltiApiController@TipaltiProxyGetPOPaymentFiles')->name('tipalti_proxy_get_po_payment_files');

    /* Woocommerce Webhooks */
    Route::any('woocommerce/order/{id}', 'Woocommerce\WoocommerceApiController@ReceiveOrderWebhook');
    Route::any('woocommerce/product/{id}', 'Woocommerce\WoocommerceApiController@ReceiveProductWebhook');
    Route::any('woocommerce/customer/{id}', 'Woocommerce\WoocommerceApiController@ReceiveCustomerWebhook');
    /* Brightpearl Webhooks */
    Route::any('brightpearl/shipment/{id}', 'Brightpearl\BrightPearlApiController@ReceiveShipmentWebhook'); //{"4":"GET_SHIPMENTONCREATE"}, {"accountCode":"apiworxtest3","resourceType":"goods-out-note","id":"67166","lifecycleEvent":"created","fullEvent":"goods-out-note.created","raisedOn":"2023-03-17T06:13:02.848Z","brightpearlVersion":"4.95.2936"}
    Route::any('brightpearl/inventory/{id}', 'Brightpearl\BrightPearlApiController@ReceiveInventoryWebhook');
    Route::any('brightpearl/product/{id}', 'Brightpearl\BrightPearlApiController@ReceiveProductWebhook'); //{"accountCode":"diamondbranding","resourceType":"product","id":"5131,10268","lifecycleEvent":"modified","fullEvent":"product.modified.on-hand-modified","raisedOn":"2023-03-17T06:12:12.088Z","brightpearlVersion":"4.95.2936"}
    Route::any('brightpearl/invoice/{id}', 'Brightpearl\BrightPearlApiController@ReceiveInvoiceWebhook');
    Route::any('brightpearl/goodsinnote/{id}', 'Brightpearl\BrightPearlApiController@ReceiveGoodsInNoteWebhook');

    Route::any('BrightpearlToTaxJar/brightpearl-modified-order-status/{id}', 'Customize\BrightpearlToTaxJarApiController@ReceiveBrightpearlModifiedOrderStatusWebhook');

    /* ShipBob Webhooks */
    Route::any('shipbob/shipment/{id}', 'ShipBob\ShipBobApiController@ReceiveShipmentWebhook');

    /* Infoplus Product Webhooks */
    Route::any('infoplus/product/{id}', 'Infoplus\InfoplusApiController@ReceiveProductWebhook');
    Route::get('infoplus/lastdate/{user_integration_id}', 'Infoplus\InfoplusApiController@GetProductLastUpdateDate');
    /* ShipHawk Webhooks */
    Route::any('shiphawk/shipment/{id}', 'ShipHawk\ShipHawkController@getShipmentForOrdersFromShiphawk');

    /* shipstation Webhooks */
    Route::any('shipstation/shipment/{id}', 'Shipstation\ShipstationController@getShipmentForOrdersFromShipstation');

    /* GoogleSheet Callback Url */
    Route::any('googlesheet/customer/create/{id}', 'Google\GoogleSpreadsheetController@addCallbackCustomerData');
    Route::any('googlesheet/product/create/{id}', 'Google\GoogleSpreadsheetController@addCallbackProductData');


    /* Infoplus Product Webhooks */
    Route::any('infoplus/product/{id}', 'Infoplus\InfoplusApiController@ReceiveProductWebhook');
    Route::get('infoplus/lastdate/{user_integration_id}', 'Infoplus\InfoplusApiController@GetProductLastUpdateDate');
    /* ShipHawk Webhooks */
    Route::any('shiphawk/shipment/{id}', 'ShipHawk\ShipHawkController@getShipmentForOrdersFromShiphawk');
    /* GoogleSheet Callback Url */
    Route::any('googlesheet/customer/create/{id}', 'Google\GoogleSpreadsheetController@addCallbackCustomerData');
    Route::any('googlesheet/product/create/{id}', 'Google\GoogleSpreadsheetController@addCallbackProductData');

    Route::any('bigcommerce/order/{id}', 'Bigcommerce\BigcommerceController@webhookBigcommerceOrders');
    Route::any('bigcommerce/product/{id}', 'Bigcommerce\BigcommerceController@webhookBigcommerceProduct');

    /* Squarespace webhook */
    Route::any('squarespace/product/{id}', 'Squarespace\SquarespaceController@ReceiveProductWebhook');
    Route::any('squarespace/order/{id}', 'Squarespace\SquarespaceController@ReceiveOrderWebhook');

    /* MFTGateway Webhooks */
    Route::any('mftgateway/shipment', 'MFTGateway\MFTGatewayApiController@ReceiveShipmentResponseWebhook');

    /* HubSpot Webhooks */
    Route::any('hubspot/{user_integration_id}', [HubSpotApiController::class, 'webhookResponse']);

    /* ShipHero Webhooks */
    Route::any('shiphero/inventory/{id}', 'ShipHero\ShipHeroApiController@ReceiveInventoryWebhook');
    Route::any('shiphero/shipment/{id}', 'ShipHero\ShipHeroApiController@ReceiveShipmentWebhook');
    Route::any('shiphero/po_revieved/{id}', 'ShipHero\ShipHeroApiController@ReceivePOWebhook');

    /* Extensiv billing manager webhooks */
    Route::any('bill-manager-hook', 'ExtensivBillingManager\ExtensivBillingManagerApiController@receiveBillManagerHook');

    /* QuickBooks Path */
    Route::any('disconenctQboOnline', 'QuickBooks\QuickBooksApiController@disconnectQboOnline')->name('quickbooks.disconnectQboOnline');

    /** James and James callback urls */
    Route::any('james/callback_order', 'JamesAndJames\JamesApiController@handleCallbackSO')->name('callback.order');
    Route::any('james/callback_asn', 'JamesAndJames\JamesApiController@handleCallbackASN')->name('callback.asn');

    Route::post('audit_log', 'MyAppsController@getAuditLogList');

});

/* WHMCS Webhooks */
Route::post( 'whmcs-webhook-ticket-detail/{user_integration_id}', [WhmcsApiController::class, 'getWebhookData'] );
    