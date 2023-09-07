<?php
namespace App\Helper\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Redis;

class CacheService implements CacheInterface
{
	
	protected $cache;
	protected $seconds;
	/**
		* Construct
		*
		* @param CacheManager $cache    
		* @param integer $seconds
	*/
	public function __construct(CacheManager $cache, $seconds)
	{
		$this->cache = $cache;
		$this->seconds = $seconds;
	}

	/* Get cache by key */
	public function get($key)
	{
		if(env('CACHE_DRIVER') == 'redis'){
			return Redis::get($key);
		}else{
			return $this->cache->get($key);
		}
	}

	/* Put cache by key,value and by seconds */
	public function put($key, $value, $seconds = null)
	{
		if(is_null($seconds))
		{
			//if you explicitly pass time 
			$seconds = $this->seconds;
		}
		if(env('CACHE_DRIVER') == 'redis'){
			return Redis::setex($key, $seconds, $value);
		}else{
			return $this->cache->put($key, $value, $seconds);
		}
		
	}

	/* Put cache forever by key,value */
	public function forever($key, $value)
	{
		return $this->cache->forever($key, $value);
	}

	/* Check key exist or not */
	public function has($key)
	{
		if(env('CACHE_DRIVER') == 'redis'){
			return Redis::get($key) ? true: false; // Redis::has() method not register so that  Redis::get() to check item exist on cache or not
		}else{
			return $this->cache->has($key);
		}
	}

	/* flush all the cache in once */
	public function flush()
	{
		if(env('CACHE_DRIVER') == 'redis'){
			return Redis::flushall(); //it will clear all data stored in Redis
		}else{
			return $this->cache->flush();
		}
	}
	
	/* Clear cache by key */
	public function forget($key)
	{
		if(env('CACHE_DRIVER') == 'redis'){
			return Redis::del($key); 
		}else{
			return $this->cache->forget($key);
		}
	}
}
	
