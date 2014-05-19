<?hh

class CacheNotConnectedException extends Exception
{
    public function __construct($message)
    {
        parent::__construct($message);
        $log = Logger::getLogger('exception');
        $log->fatal('cache not connected: ' . $message);
    }
}

class NoCacheServersDefinedException extends Exception
{
    public function __construct($message)
    {
        parent::__construct('no defined cache servers: ' . $message);
        $log = Logger::getLogger('exception');
        $log->fatal('no defined cache servers: ' . $message);
    }
}

interface Cache
{
    public function setKey(string $key): Cache;
    public function get(?string $key): mixed;
    public function set(mixed $value, int $expire = 3600, ?string $key = null): bool;
    public function delete(?string $key = null): ?Cache;

	public static function getInstance(): Cache;
}

/**
 * todo: docme
 * @author Cory
 * @version 1.0
 */
abstract class AbstractCache implements Cache
{
	protected $_log;
	protected $_key;
	protected $_origKey;

	const CACHE_MISS = 'CACHEMISS';

	/**
	 * todo: docme
	 */
	protected function __construct()
	{
        $this->_log = Logger::getLogger('cache');
		$this->_key = null;

		if (!defined('LIB_CACHE_ENABLE'))
		{
			define('LIB_CACHE_ENABLE', 0);
			Logger::getLogger('cache')->error('setting LIB_CACHE_ENABLE is not defined, ' .
					'turning cache off. define LIB_CACHE_ENABLE to 1 to enable cache');
		}
    }

	/**
	 * todo: docme
	 * @param string $key the new cache key to use for the next calls to get, set, delete
	 *    if $key is not a string, it will be serialized()
	 */
	public function setKey(string $key): Cache
	{
		$this->_origKey = $key;

		$this->_key = $this->createKey($key);
		$this->_log->trace("new key: [{$this->_origKey}] => {$this->_key}");
		return $this;
	}

	protected function createKey(string $key): string
	{
        // hash it if we need to
        if (strspn($this->_key, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-') != strlen($this->_key))
			return md5($this->_key);

		return $key;
	}

	
	/**
	 * @return mixed something that was cached
	 */
	abstract public function get(?string $key): mixed;
	

	/**
	 * @return true on success
	 */
	abstract public function set(mixed $value, int $expire = 3600, ?string $key = null): bool;

	/**
	 * @return true on success
	 */
	abstract public function delete(?string $key = null): ?Cache;

}
