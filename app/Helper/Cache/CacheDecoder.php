<?php

namespace App\Helper\Cache;

use App\Helper\Cache\CacheService;

class CacheDecoder
{
	protected $service, $seconds;
	public function __construct($seconds = 3600)
	{
		$this->$seconds = $seconds;
		$this->service = new CacheService(app()->make('cache'), $this->$seconds);
	}

	/* 
			$key =set unique key 
			$value =set value (optional)
			$seconds = (optional) if you not pass seconds by default=3600 (60 min)
			$cache_type = by default null if you want to store forever just pass arg=forever
		*/
	public function get_or_set($key, $value = null, $seconds = null, $cache_type = null)
	{
		$return = null;
		$key = md5("cache_" . $key);
		if ($this->service->has($key)) {
			if ($value) {
				$return = $this->set_cache($key, $value, $seconds, $cache_type);
			} else {
				$return = $this->service->get($key);
			}
		} elseif ($value) {
			$return = $this->set_cache($key, $value, $seconds, $cache_type);
		}

		return $return;
	}

	/* set cache value */
	private function set_cache($key, $value = null, $seconds = null, $cache_type = null)
	{
		if (is_null($seconds)) {
			$seconds = $this->seconds;
		}
		$this->clear_cache_by_key($key); //before set clear the value
		if ($cache_type == "forever") {
			$this->service->forever($key, $value);
		} else {
			$this->service->put($key, $value, $seconds);
		}
		return $value;
	}

	/* forget the cache by key */
	public function clear_cache_by_key($key)
	{
		$return = null;
		$key = md5("cache_" . $key);
		if ($this->service->has($key)) {
			$return =  $this->service->forget($key);
		}
		return $return;
	}

	/* please don't use this method , this will clear all the cache from project */
	public function clear_all_cache()
	{
		return $this->service->flush();
	}

	//get integration details from cache
	public function getIntegrationDetailsFromCache($userIntegrationId){
		$key = $this->generateCacheKey($userIntegrationId, 'user_integrations_detail');
        $data = $this->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
        return ['key'=>$key, 'data'=>json_decode($data)];
	}

	//get workflow details from cache
	public function getWorkFlowDataFromCache($userIntegrationId){
        $key = $this->generateCacheKey($userIntegrationId, 'intginfo');
        $data = $this->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
        return ['key'=>$key, 'data'=>json_decode($data,true)];
    }

	//check product webhook occur for $event_name like product.created, product.modified, store/product/created 
	public function checkProductInCache($userIntegrationId, $product_id, $event_name){
		$key = $this->generateProductCacheKey($userIntegrationId, $product_id, $event_name);
		$data = $this->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
		return ['key'=>$key, 'data'=>$data];
	}

	//clear cache on integration disconnect & on flow on off
	public function clearAllCacheForIntegration($userIntegId){
		$cache_clear_for = ['user_integrations_detail','intginfo','inactive']; 
        foreach($cache_clear_for as $ccf){
            $key = $this->generateCacheKey($userIntegId, $ccf);
            $find_in_cache = $this->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
            if ($find_in_cache) {
                $this->clear_cache_by_key($key);
            }
        }
	}

    public function generateCacheKey($user_integration_id, $cache_for)
	{
			return $user_integration_id.'_'.$cache_for; //like 582_intginfo
	}

	public function generateProductCacheKey($userIntegrationId, $product_id, $event_name) 
	{
		return $userIntegrationId.'_id_'.$product_id.'_'.$event_name; //like 582_id_1_eventname
	}

	
}
