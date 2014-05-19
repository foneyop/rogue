<?hh
require_once LIB_DIR . '/cache/ActractCache.php';


/**
 * memcache impl
 *
 * @property NetworkCache $_instance the singleton ref to
 * @property MemCache $_cache the actual memcache object ref
 * @property boolean $_connected true if we have a good memcache connection
 * @property integer $_key the memcache key (crc32 hash)
 * @property string $_origKey the memcache key in human format
 *
 * @author Cory
 * @package lib
 */
class NetworkCache extends AbstractCache
{
	private static $_instance;
    protected $_cache;
	protected bool $_connected;
	
	/**
	 * connect to the memcache servers.  Actually this will setup the connection "pool"
	 */
	protected function  __construct()
	{
        parent::__construct();

		$this->_log = Logger::getLogger('cache');
		$this->_connected = false;

		// else, create the new connection
        if (LIB_CACHE_ENABLE)
            $this->startCache();
        else
            return $this->_log->warn('NetworkCache is not enabled.');

		// memcache addServer is broken, always returns false.. oh well, maybe someday it will work...
		$this->_connected = true;
	}

	/**
	 * called on add server failure
	 * @param string $host host add failure host
	 * @param string $port tcp port
	 */
    public static function MemCacheFailure($host, $port)
    {
        $log = Logger::getLogger('cache');
        $log->fatal("Memcache server connection error to host: $host:$port");
    }

	/**
	 * called by constructor to connect to cache servers
	 * @return Memcache reference to Memcache object
	 */
    private function startCache()
    {
        if (isset($this->_cache))
            return;

        // else, create the new connection
        $cache = new Memcache();
        $serverCount = 0;

        for ($S = 1; $S <= 10; $S++)
        {
            $server = 'MEMCACHE_SERVER'.$S;
            if (defined($server))
            {
                $cache->addServer(constant($server), 11211, true, 100, 1, 15, true, 'NetworkCache::MemCacheFailure');
                $serverCount++;
            }
        }

        if (LIB_CACHE_ENABLE && $serverCount == 0)
            throw new NoCacheServersDefinedException("no MEMCACHE_SERVER[1-10] defines");

        $this->_cache = $cache;
    }

	/**
	 * get a singleton reference to the mem cache object.  Be sure to set
	 * a namespace before using the cache.  You can specify a namespace here
	 * of use the setNameSpace method.
	 *
	 * @see setNameSpace
	 * @param $nameSpace the name of the namespace to connect to, only
	 *   required for nameSpace cached items;
	 * @return NetworkCache
	 */
	public static function getInstance(): NetworkCache
	{
		if (isset(self::$_instance))
            return self::$_instance;

        $ref = new NetworkCache();
        return self::$_instance = $ref;
	}

	/**
	 * test if the cache is connected to the server(s).  This is difficult to do
	 * when using the "pool" since no connection is attempted until the fist
	 * get or put request.  At that point it will either connect or fail.
	 *
	 * It is also difficult to test all servers since some keys will be mapped
	 * to different servers and while some tests may work, tests with other keys
	 * mapped to different servers may fail.  For these reasons out of band
	 * server testing should determine what servers are up, and only up servers
	 * should be added to the pool to be used.  This creates the additional
	 * problem of key remapping if a server is added to the pool altering the
	 * server mapping.  A server key mapping strategy needs to be employed for
	 * the best results.
	 *
	 * has this ever really worked?
	 *
	 * @return boolean true if this object is connected to MemCache, false otherwise
	 *   currently this will always return true.
	 */
	public function isConnected()
	{
		return $this->_connected;
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
	public function get($throws = false, $flags = false)
	{
        if (!LIB_CACHE_ENABLE)
            return $this->_log->warn('Cache is not enabled LIB_CACHE_ENABLE = false');

		$this->_log->logTrace('get item');

		// don't even attempt if memcache is not connectecd
		if (!$this->isConnected())
			return $this->_log->warn('memcache is not connected, get failed!');

        // if someone passed in a key name to get, then try to work with that
	    if (is_string($throws) && strlen($throws) > 3)
        {
            $this->_key = $this->createKey($throws);
            $this->_log->error("!!!! ID10T error, you called get() with a keyname, use createKey() instead.");
        }

		// get the data from the cache pool
		$result = $this->_cache->get($this->_key);
		//$this->_log->logTrace('get ' . $this->_key . ' item was: ' . print_r($result, true));
		if ($result == false && $throws === true)
			throw new RuntimeException("key: {$this->_origKey} was not found");
		else if ($result == false)
			return $this->_log->debug("key: {$this->_origKey} was not found in cache");
		else
			$this->_log->debug("key: {$this->_origKey} is used from cache");

		return $result;
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
	public function set($value, $expire = 3600, $flags = false, $throwOnFail = false)
	{
        if (!LIB_CACHE_ENABLE)
            return $this->_log->warn('Cache is not enabled.');

        $this->_log->logTrace('set ' . $this->_key . ' for ' . $expire . " sec");

		// don't even attempt if memcache is not connectecd
		if (!$this->isConnected())
			return $this->_log->warn('memcache is not connected, set failed!');

		// dont' set the thing if we don't have a cache key, log errors on errors
		if ($this->_key != false)
			$result = $this->_cache->set($this->_key, $value, $flags, $expire);
		else
			throw new BSException("unable to cache an item because the key was not defined!");

		if (!$result && $throwOnFail)
			throw new BSException("setting the cache value for '{$this->_origKey}' failed! [$result]");

		return $result;
	}


	/**
	 * delete the cached value for the current key, see createKey to define the
	 * key to get the cache value for.  If the key exists, an exception is thrown
	 *
	 * @see createKey for how to define the cache key
	 * @see createNameSpaceKey for how to define the cache key
	 * @param boolean $throwOnFail throw an exception if the delete fails (default false)
	 * @throws BSException if setting the key fails, or it already exists
	 * @throws BSException if memcache is not connected
	 * @return true on success
	 */
	public function delete($throwOnFail = false)
	{
        if (!LIB_CACHE_ENABLE)
			return $this->_log->info('no cache delete, cache not enabled');

		$this->_log->logTrace('Deleting Cache entry by key: '.$this->_key);

		// don't even attempt if memcache is not connectecd
		if (!$this->isConnected())
			return $this->_log->warn('memcache is not connected, delete failed!');

		// dont' set the thing if we don't have a cache key, log errors on errors
		if ($this->_key != false)
			$result = $this->_cache->delete($this->_key);
		else
			throw new CacheNotDefinedException($this->_key);

		if (!$result && $throwOnFail == true)
			throw new CacheDeleteException($this->_origKey);

		return $result;
	}

    /**
     * Passthrough for the flush method on the memcache object.
     */
    public function flush()
    {
        return $this->_cache->flush();
    }
	/**
	 * Passthrough method to get stats from memcache object
	 * @return array stats from memcache object
	 */
	public function getstats()
	{
		return $this->_cache->getstats();
	}
}
