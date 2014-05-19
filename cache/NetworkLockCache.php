<?hh
require_once LIB_DIR . '/cache/NetworkCache.php';

/**
 * locked memcache
 *
 * @author Cory
 * @package lib
 */
class NetworkLockCache extends NetworkCache {
    private static $_instance;

    /**
     * connect to the memcache servers.  Actually this will setup the connection "pool"
     */
    protected function  __construct() {
        parent::__construct();
    }


    /**
     * get a singleton reference to the mem cache object.  Be sure to set
     * a namespace before using the cache.  You can specify a namespace here
     * of use the setNameSpace method.
     *
     * @see setNameSpace
     * @return NetworkLockCache
     */
	public static function getInstance(): NetworkLockCache
	{
        if (isset(self::$_instance))
            return self::$_instance;

        $ref = new NetworkLockCache();
        return self::$_instance = $ref;
    }

    /**
     * get the cached value for the current key, see createKey to define the
     * key to get the cache value for.
     *
     * @see createKey for how to define the cache key
     * @see createNameSpaceKey for how to define name space cache keys
     *
     * @param boolean $throws set to boolean true if we should throw sql exceptions if the
     *   data was not found.  This makes it easy to use a try catch block to get
     *   the data from cache, and load it if cache retrevial failed
     * @param integer $flags
     * @throws BSException if memcache is not connected
     * @throws RuntimeException if the cached data was not found
     * @return mixed the thing that was cached in memcache
     */
    public function get($throws = false, $flags = false) {
        if (!$this->_connected)
            return false;

        $result = parent::get($throws, $flags);
        if ($result == false || !isset($result[1]))
            return $result;

        // data is about to expire
        if ($result[0] < time()) {
            $lockNum = $this->_cache->increment($this->_key . '-lock');
            if ($lockNum == 2)
                return $this->_log->warn('returning false for a cached item because it is about to ' .
                        'expire and we aquired a lock to update it');
        }

        return $result[1];
    }


    /**
     * set the cached value for the current key, see createKey to define the
     * key to get the cache value for.  If the key exists, it is overwritten
     *
     * @see createKey for how to define the cache key
     * @see createNameSpaceKey for how to define name space cache keys
     *
     * @param mixed $value the data to cache
     * @param integer $expire the number of seconds the item expires, default is 1 hour
     * @param integer $flags any flags to set during the memcache set, default is false
     * @param boolean $throwOnFail set to true to throw excpetion on cache set fail
     * @throws BSException if setting the key fails
     * @throws BSException if memcache is not connected
     * @return true on success
     */
    public function set($value, $expire = 3600, $flags = false, $throwOnFail = false) {
        if (!$this->_connected)
            return false;
        $toCache = array(time() + $expire, $value);
        parent::set($toCache, $expire + 300, $flags, $throwOnFail);
        return $this->_cache->set($this->_key . '-lock', 1, $flags, $expire + 300);
    }


    /**
     * delete the cached value for the current key, see createKey to define the
     * key to get the cache value for.  If the key exists, an exception is thrown
     *
     * @see createKey for how to define the cache key
     * @see @seecreateNameSpaceKey for how to define the cache key
     * @param boolean $throwOnFail throw an exception if the delete fails (default false)
     * @throws BSException if setting the key fails, or it already exists
     * @throws BSException if memcache is not connected
     * @return true on success
     */
    public function delete($throwOnFail = false) {
        if (!$this->_connected)
            return false;
        parent::delete($throwOnFail);
        return $this->_cache->delete($this->_key . '-lock');
    }
}
