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
	'SyncInventoryInBP' => ['infoplus' => 50, 'ahlsell' => 100, 'jasci' => 50],
	'SyncBulkInventoryInBP' => ['jasci' => 300, 'infoplus' => 100],
	'SyncProductInBP' => ['bigcommerce' => 50],
];
