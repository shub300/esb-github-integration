<?php
/*
		|--------------------------------------------------------------------------
		| For Conditions Only those platform which is not needed to another platform to call API
		|--------------------------------------------------------------------------
		| Call By condition only for those platform which we need
		|
		|
	*/

return [
	'GetContact' => ['tipalti', 'brightpearl'], //Called in Get PO for BP
	'HideEventsFromLog' => ['GET_CHECKORDERSTATUS'],
	'DiscountCouponTax' => ['woocommerce'], //This is used basically for insert tax amt as (-) minus value
	'GOFinalShipment' => ['3pl', 'shiphawk', 'ups', 'amazonmcf', 'wayfair', 'shipstation', 'shipbob'], //Goods out note final shipment
	'DisplayOrderNumberInBP' => ['woocommerce' => 1, 'gunbroker' => 1], //Display BP order number in order_number field in display log
	'FindShippingMethodFor3PLForTrackingInformationInBP' => ['3pl' => 1], //This setting is basically used to find shipping method by name in BrightPearlApiContoller (SyncTrackingInformation method)
	'FindShippingMethodForShiphawkForTrackingInformationInBP' => ['shiphawk' => 1, 'amazonmcf' => 1, 'wayfair' => 1, 'shipbob' => 1], //This setting is basically used to find shipping method by api_id in BrightPearlApiContoller (SyncTrackingInformation method)
	'PullLineItemsInReverseOrder' => [],
	'CustomerGroupAndPriceListMappingPlatformForOrder' => ['bigcommerce'],
	'CustomerNameCustomBpIdMappingInBP' => ['smartsheet' => 1],
	'PartialDoNotAllowToPassProductName' => ['smartsheet' => 1],
	'AllowPriceFromPriceListInBPOrder' => ['smartsheet' => 1],
	'AllowAddCancelOrderNoteInBP' => ['amazonmcf' => 1],
	'AllowCustomerUpdateInBrightPearl' => ['bigcommerce' => 1, 'brandwise' => 1], //array contain source platform name for which customer to be updated.
	'shipmentInformationNotes' => ['shiphawk', 'amazonmcf'],
	'UpdateInventoryStatusIgnored' => ['shipbob','infoplus'], //if non tracked product or inventory information is not found in destination platform
	'FindBrightpearlShipmentSalesOrder' => ['shipbob'], //find brightpearl sales order when order shipment time order not available in database
	'SaveBrightpearlCreatedSalesOrderData' => ['shipbob' => 1], //save brightpearl new create sales order when order created source platform to  brightpearl
	'AllowDestroyGoodsOutNoteEventIntegrationId' => [234, 204, 363], //add integration id for which you want to allow webhook to destroy goods out notes
	'AllowShipmentAndTransferTypeInBP' => ['infoplus', 'shipstation'], //add integration name when you have Goods Out Note & Transfer Type Of GON
	'AllowGONDeletedWebhookCreationInBP' => ['infoplus' => 1, 'shiphawk' => 1, '3pl' => 1, 'amazonmcf' => 1, 'shipstation' => 1], //add integration name when you have Goods Out Note & Transfer Type Of GON
	'RestrictCustomFieldActionInCreateGoodsInNoteInBP' => ['infoplus' => 1], //add integration name when you don't need Custom Field Action while creating the goods in note for purchase order in Brightpearl
	'BpSOsyncStatusPending' => ['amazonvendor', 'bigcommerce'], //This is used for amazonvendor & bp integration in amazon PO acknowledge
	'BpSOStatusCheckUpdate' => ['bigcommerce'], //This is used for bigcommerce & bp integration in amazon PO acknowledge
	'AllowShipmentCheckForNonSyncedStatusOrderInSkuvault' => ['spscommerce' => 1], //This setting is basically used to allowing ready state order need to check for shipment process. we can not wait for ack order.
	'AllowQueryingOrderLineItemForShipmentInSpscommerce' => ['brightpearl' => 1], //This setting is basically used to add join of platform order line for brightpearl for some special case.
	'AllowDateConversionInBPOrder' => ['cscart' => 1],
	'CustomCronRunTime' => ['infoplus' => 1],
	'AllowOrderStatusUpdateInBP' => ['bigcommerce' => 1], //Allow order status update in BP
	'AllowOrderNumberCheckInShipBob' => ['netsuite' => 1], //Allow order number checking in destination platform shipbob
	'PlatformCheckProductTwoWaySyncExistInBP' => ['bigcommerce' => 1], //Block loop back
	'SaveAdditionalOrderFromInvoiceOnIntacctWhenDest' => ['blackline' => 1], //save additional intacct order when destination exists
	'AllowJournalEntryInBP' => ['tipalti' => 1], //Allow to add journal entry in BP
	'CheckAccountCodeInBP' => ['tipalti' => 1], //Allow to check account code for customer in BP
	'FindLOBToSyncInventoryInBP' => ['infoplus' => 1], //find LOB from source platform
	'allowBundleCheckInWF' => ['brightpearl' => 1], //check bundle product mapped for wayfair-bp
	'sendSecondOrMoreShipmentInfoInNoteInBp' => ['shiphawk' => 1],
	'addShippingCost' => ['shipstation' => 1],
	'checkWHEventForDestPlatForOnGetShipmentInBP' => ['wayfair' => 1],
	'preventDelAndAllowUpdateOnShipmentWhInBP' => ['wayfair' => 1],
	'SkipTransactionInBrightpearl' => ['woocommerce' => 1], //This is basically used to skip amount transaction in brightpearl if currency and paid date not found
	'hideElmOrFeatureForDomain' => ['extensiv' => 1],
	'acceptLiveProductsInBp' => ['jasci' => 1], //filter brightpearl product accept only live
	'AllowUpdateOnlyOrderStatusInBP' => ['kefron' => 1], //filter brightpearl order to update order status only in db to keep upto date status for processing on destinations
	'useInStockForInventoryCorrection' => ['jasci' => 1],
	'makeOrderStatusReadyInShipbob' => ['brightpearl' => 1], // If there is a source platform "status update" flow then it keeps platform info which sync status needs to be Ready (to be sync back)
	'UpdateTrackingInfoOnCustomFieldInBp' => ['shipstation' => 1],
	'AllowCurrencyExchangeInBP' => ['jasci' => 1],
	'AllowColumWiseFilterInNetsuite' => ['snowflake' => 1],
	'AllowSKUInSnowflake' => ['netsuite' => 1],
	'UniqueIdentityForSnowflakeSoMutate' => [
		'peoplevox' => [
			'api_product_id',
			'api_product_id'
		],
		'tiktok' => [
			'api_product_id',
			'api_variant_id'
		],
		// 'logiwa' => [
		// 	'api_product_id',
		// 	'api_variant_id'
		// ],
	],
	'UniqueIdentityForInventoryPlannerSoMutate' => [
		'peoplevox' => [
			'api_product_id',
			'api_variant_id'
		],
		'tiktok' => [
			'api_product_id',
			'api_variant_id'
		],
		'logiwa' => [
			'api_product_id',
			'api_variant_id'
		],
	],
	'UniqueIdentityForSnowflakeOrderReceiptMutate' => [
		'peoplevox' => 'api_product_id',
	],
	'IgnoreWarehouseMapInSoSync' => ['peoplevox' => 1],
	'AllowOrderCustomFilterInNetSuite' => ['shipbob' => 1],
	'AllowOrderCreationInNetSuiteForBP' => ['brightpearl' => 1],
	

	'uploadMappingFilesIn' => 's3bucket', //define where to upload mapping files s3bucket or local
	'AllowProductDataFromCache' => ['brightpearl' => 1, 'bigcommerce' => 1], //Allow product data from caching
	'AllowIntegrationDataFromCache' => ['brightpearl' => 1, 'amazonvendor' => 1, 'bigcommerce' => 1, 'infoplus' => 1, 'magento' => 1, 'netsuite' => 1, 'shipbob' => 1, 'shiphero' => 1, 'wayfair' => 1, 'woocommerce' => 1, 'zulily' => 1], //Allow integration data from caching
	'AllowFlowDataFromCache' => ['bluecherry' => 1, 'brightpearl' => 1, 'cscart' => 1, 'netsuite' => 1, 'shipbob' => 1, 'shiphero' => 1, 'woocommerce' => 1, 'teapplix' => 1], //Allow flow data from caching
	'calculateUnitPriceByShipmentQtyInBP' => ['jasci' => 1], // unit price = price/qty in shipment
	'AllowDailySyncOnceInBP' => [
		'shipbob' => [
			'timezone' => 'America/Phoenix', // The timezone according by which to process the sync.
			'start_time' => '1', // From this time sync has to start
			'end_time' => '3', // After this time sync has to stop
		],
	],

	'markOrderFullySynced' => ['jasci' => 1], // check when is_fully_synced == 0
	'showDetailOptionInAuditLog' => ['extensivbillingmanager' => 1], // enable this to show detals log
	'showDetailOptionForEventsInAuditLog' => ['GET_INVOICE'], // enable this to show detals log
	'sendRefreshTokenReSyncNotification' => 'sneha@apiworx.com, support@apiworx.com, subhadra@apiworx.com, nida@apiworx.com',
];
