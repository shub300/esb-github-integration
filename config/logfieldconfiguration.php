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

	'displaySpecificFieldByCase' => [
		'quickbooks&extensivbillingmanager' => [
			'sourceRef' => [
				'GET_CUSTOMER' => 'company_name',
			],
			'destinationRef' => [
				'GET_PURCHASEORDER' => 'order_number',
				'GET_SALESORDER' => 'order_number',
				'GET_PRODUCT' => 'sku',
				'GET_INVENTORY' => 'sku',
				'GET_POITEMRECEIPT' => 'order_number',
				'GET_CUSTOMER' => 'company_name',
			]
		],
		'quickbooks&skubana' => [
			'sourceRef' => [],
			'destinationRef' => [
				'GET_SALESORDER' => 'order_number',
			]
		],
		'exacterp&snowflake' => [
			'sourceRef' => [
				'GET_PRODUCT' => 'product_name',
				'GET_PRODUCTINVENTORY' => 'product_name',
				'GET_SUPPLIERS' => 'customer_name',
				'GET_CUSTOMER' => 'customer_name',
				'GET_SALESORDER' => 'api_order_id',
				'GET_PURCHASEORDER' => 'api_order_id',
			],
			'destinationRef' => [
				'GET_PRODUCT' => 'product_name',
				'GET_PRODUCTINVENTORY' => 'product_name',
				'GET_SUPPLIERS' => 'customer_name',
				'GET_CUSTOMER' => 'customer_name',
				'GET_SALESORDER' => 'api_order_id',
				'GET_PURCHASEORDER' => 'api_order_id',
			]
		],
		'brightpearl&shipbob' => [
			'sourceRef' => [],
			'destinationRef' => [
				'GET_GOODSOUTNOTECREATED' => 'linked_api_order_id',
			]
		],
		'spscommerce&intacct' => [
			'sourceRef' => [
				'GET_INVOICE' => 'invoice_code',
			],
			'destinationRef' => [
				'GET_PURCHASEORDER' => 'order_number',
			]
		],
		'logiwa&snowflake' => [
			'sourceRef' => [
				'GET_PRODUCT' => 'sku',
				'GET_PRODUCTINVENTORY' => 'sku',
				'GET_SALESORDER' => 'order_number',
			],
			'destinationRef' => [
				'GET_PRODUCT' => 'sku',
				'GET_PRODUCTINVENTORY' => 'sku',
				'GET_SALESORDER' => 'api_order_id',
			]
        ],
        'netsuite&snowflake' => [
            'sourceRef' => [
                'GET_PRODUCT' => 'sku',
                'GET_INVENTORY' => 'sku',

            ],
            'destinationRef' => [
                'GET_PRODUCT' => 'sku',
                'GET_INVENTORY' => 'sku',
            ]
        ]
	],
];
