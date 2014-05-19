<?hh
require_once ROGUE_DIR . '/cache/AbstractCache.php';
/**
 * ex: tags=php.tags:
 * cache impl
 *
 * @property boolean $_useApc APC enabled
 * @property boolean $_useEacc EACCELERATOR enabled
 *
 * @author Cory
 * @package lib
 */
class LocalCache extends AbstractCache
{
    protected static $_instance;
    private $_useApc = false;
    private $_useEacc = false;
	private $_miss = 0;
	private $_hit = 0;
	private $_set = 0;
	private $_delete = 0;
	protected $_connected = false;

    /**
     * connect to the cache(ish)
     */
    protected function __construct()
    {
        parent::__construct();
        if (ini_get('apc.enabled') == "1")
            $this->_useApc = 1;
        else if (ini_get('eacceleratopr.enable') == "1")
            $this->_useEacc = 1;
	    else
	    {
			$this->_log->fatal('local cache requested, but APC and EACCELERATOR are disabled');
			$this->_connected = false;
	    }

        // no cache available
        $this->_connected = true;
    }

    /**
	 * @return LocalCache
     */
    public static function getInstance(): Cache
    {
        if (isset(self::$_instance))
            return self::$_instance;

        return self::$_instance = new LocalCache();
    }


    /**
	 * @return mixed the thing that was cached in memcache
     */
    public function get(?string $key): mixed
    {
		// is this enabled?
        if (!LIB_CACHE_ENABLE)
            return self::CACHE_MISS;

        // handle cache not available
        if (!$this->_connected)
		{
            $this->_log->debug('local cache fail: cache not available');
            return self::CACHE_MISS;
		}

		// set the class key for future reference...
		if ($key != null)
			$this->_key = $key;

        // make sure we have a key
        if ($this->_key == null)
            throw new InvalidArgumentException('no cache key');

        $result = $success = false;
        if ($this->_useApc)
        {
            $result = apc_fetch($this->_key, $success);
			// handle arrays ...
            if ($result instanceof ArrayObject)
                $result = $result->getArrayCopy();
        }
        else if ($this->_useEacc)
        {
            $result = eaccelerator_get($this->_key);
			// do we need to handle arrays here ?
            $success = ($result == null) ? false : true;
        }

        if ($success != false)
		{
			$this->_hit++;
            $this->_log->debug("CACHE fetch: {$this->_origKey} hit");
		}
        else
		{
			$this->_miss++;
			$result = self::CACHE_MISS;
            $this->_log->debug("CACHE fetch: {$this->_origKey} miss");
		}

        return $result;
    }


    /**
	 * @return true on success
     */
    public function set(mixed $value, int $expire = 3600, ?string $key = null): bool
    {
		// is this enabled?
        if (!LIB_CACHE_ENABLE)
            return false;

        // return false if there is no cache available
        if (!$this->_connected)
		{
            $this->_log->debug('local cache fail: cache not available');
			return false;
		}

		// set the class key for future reference...
		if ($key != null)
			$this->_key = $key;

        // make sure we have a key
        if ($this->_key == null)
            throw new InvalidArgumentException('no cache key');


        // get the data from the cache pool
        $success = false;
        if ($this->_useApc)
        {
			 $success = apc_store($this->_key, $value, $expire);
        }
        else if ($this->_useEacc)
        {
            $success = eaccelerator_put($this->_key, $value, $expire);
        }

        if ($success != false) 
		{
			$this->_set++;
            $this->_log->debug("CACHE set: {$this->_origKey} succeeded");
		}
        else
            $this->_log->debug("CACHE set: {$this->_origKey} failed");

        // return the cache value
        return $success;
    }



    /**
	 * @param string $key if set, will set the cache key to this new key for all future default key calls (until set again)
     * @return LocalCache on success, null on error
     */
    public function delete(? string $key = null): ?LocalCache
    {
        if (!LIB_CACHE_ENABLE)
            return null;

        // return false if there is no cache available
        if (!$this->_connected)
		{
            $this->_log->debug('local cache fail: cache not available');
			return null;
		}

		// set the class key for future reference...
		if ($key != null)
			$this->_key = $key;

		$result = null;
		if ($this->_useApc)
        {
			$result = apc_delete($this->_key);
			$this->_delete++;
        }
        else if ($this->_useEacc)
        {
            $result = eaccelerator_rm($this->_key);
        }

        return $result;
    }

	public function getStats(): array
	{
		return array('miss' => $this->_miss, 'hit' => $this->_hit, 'set' => $this->_set);
	}
}
