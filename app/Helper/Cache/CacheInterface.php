<?php
	namespace App\Helper\Cache;
	
	interface CacheInterface
	{
		public function get($key);
		//public function many(array $keys);
		public function put($key, $value, $minutes = null);
		//public function putMany(array $values, $minutes);
		public function has($key);
		//public function increment($key, $value = 1);
		//public function decrement($key, $value = 1);
		public function forever($key, $value);
		public function forget($key);
		public function flush();
		//public function getPrefix();
	}