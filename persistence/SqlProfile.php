<?php
/**
 * Detailed server profiling
 *
 * @author cory
 */
class SqlProfile
{
	protected static $_instance = null;
	private $_time;
	private $_queries;
	private $_dups;
	private $_qtime;
	private $_profile;


	private function  __construct()
	{
		$this->_time = 0;
		$this->_queries = array();
		$this->_qtime = array();
		$this->_profile = array();
		$this->_dups = array();
	}

	/**
	 * @return SqlProfile singleton
	 */
	public static function getInstance()
	{
		if (self::$_instance == null)
		{
			self::$_instance = new SqlProfile();
		}
		return self::$_instance;
	}

	public function profileQuery($sql, $time, $profile)
	{
        $sql = stripslashes($sql);
        if (preg_match('/^\s*select /i', $sql) && in_array($sql, $this->_queries) && !in_array($sql, $this->_dups))
            $this->_dups[] = $sql;

		$this->_queries[] = $sql;
		$this->_qtime[] = $time;
		$this->_qprofile[] = $profile;
		$this->_time += $time;
	}

	public function logProfile()
	{
		$wtime = microtime(true) - $GLOBALS['START'];
		$usage = getrusage();

		$usageText = '';
		foreach ($usage as $key => $value)
		{
			if ($value > 0)
				$usageText .= "$key = $value,  ";
		}

		$queryText = '';
		for ($i=0,$m=count($this->_queries);$i<$m;$i++)
		{
			$queryText .= $this->_queries[$i] . '\nTIME: ' . $this->_qtime . '\nPROFILE: ' . $this->_qprofile;
		}

		$lcstat = LocalCache::getInstance()->getStats();
		$ref = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';
		$db = DB::getConnection('Profile', true);
		$insert = array('ip' => "!inet_aton('{$_SERVER['REMOTE_ADDR']}')",
						'url' => $_SERVER['REQUEST_URI'],
						'utime' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] * 0.000001,
						'stime' => $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] * 0.000001,
						'wtime' => $wtime,
						'rusage' => $usageText,
						'sqltime' => $this->_time,
						'memory' => memory_get_peak_usage(true),
						'numQueries' => count($this->_queries),
						'cache' => LIB_CACHE_ENABLE,
						'agent' => $_SERVER['HTTP_USER_AGENT'],
						'referer' => $ref,
						'queries' => join('\\n', $this->_queries),
						'lcMiss' => $lcstat['miss'],
						'lcHit' => $lcstat['hit'],
						'lcSet' => $lcstat['set']
						);
		try
		{
			$db->insert('profile-page', 'current', $insert);
		}
		catch (SyntaxException $ex)
		{
			if ($db->getLastErrorNo() == 1146)
			{
				$db->sqlStmt('clone-profile', "CREATE TABLE current LIKE template", null);
				$db->insert('profile-page', 'current', $insert);
			}
			else
				Logger::getLogger('exception')->error($ex);
		}



		foreach ($this->_dups as $q)
		{
            $db->insert('log-query-dups', 'QueryDups', array('crc' => crc32($q), 'query' => $q));
		}
	}
}