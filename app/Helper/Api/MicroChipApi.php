<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	
	class MicroChipApi
	{	
		public function __construct()
		{
			$this->MainModel = new MainModel();
		}
	}