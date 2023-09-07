<?php
	namespace App\Models\Enum;
	
	class PlatformRecordType
	{
		const CUSTOMER = 'Customer';
		const EMPLOYEE = 'Employee';
		const VENDOR = 'Vendor';
		const TRANSFER = 'Transfer';
		const SHIPMENT = 'Shipment';
		const POSHIPMENT = 'POShipment';
		const SCSHIPMENT = 'SCShipment';
		const PCSHIPMENT = 'PCShipment';
		const DROPSHIPMENT = 'DropShipment';
	}