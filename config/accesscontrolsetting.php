<?php
	/*
		|--------------------------------------------------------------------------
		| Call By condition only for those platform which we need
		|
		|
	*/

return [
	'shareData' => ['brightpearl&shipstation'=>1,'brightpearl&amazonmcf'=>1,'brightpearl&brightpearl'=>1],
	'copyData' => [],
	'AllowProductDataFromCache'=> ['brightpearl'=>1,'bigcommerce'=>1,'infoplus'=>1],//Allow product data from caching
	'AllowIntegrationDataFromCache'=> ['brightpearl'=>1,'amazonvendor'=>1,'bigcommerce'=>1,'infoplus'=>1,'magento'=>1,'netsuite'=>1,'shipbob'=>1,'shiphero'=>1,'wayfair'=>1,'woocommerce'=>1,'zulily'=>1],//Allow integration data from caching
	'AllowFlowDataFromCache'=> ['bluecherry'=>1,'brightpearl'=>1,'cscart'=>1,'netsuite'=>1,'shipbob'=>1,'shiphero'=>1,'woocommerce'=>1,'teapplix'=>1],//Allow flow data from caching
];
