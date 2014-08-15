<?php
define ('FAIL_URL', 'EXCEPTION');

require_once ROGUE_DIR . '/util/ModuleInterfaces.php';

/**
 * General database abstraction layer
 *
 * @author Cory Marsh
 * @copyright XCard 2014
 */


class SQLException extends Exception
{
}
class ConnectionException extends SQLException
{
}
class SyntaxException extends SQLException
{
}
class DuplicateKeyException extends SQLException
{
}

class LogProfile implements Action
{
    /** @var DB $_db db connection instance */
    private $_db;

    /**
     * @param DB $db the database we will tell to execute post render SQL (updates, etc)
     */
    public function  __construct(DB $db)
    {
        $this->_db = $db;
    }

    /**
     * execute the update to the SQL profile
     */
    public function action()
    {
	    SqlProfile::getInstance()->logProfile();
    }
}

class PostRenderSql implements Action
{
    /** @var DB $_db db connection instance */
    private $_db;

    /**
     * @param DB $db the database we will tell to execute post render SQL (updates, etc)
     */
    public function  __construct(DB $db)
    {
        $this->_db = $db;
    }

    public function action()
    {
        $this->_db->execDelayed();
    }
}

class DB
{

	/** @var integer $_unitTest true if unit test mode*/
    static protected $_unitTest = false;
	/** @var Logger $_log internal log handle */
    static protected $_log = false;
    /** @var array $_instance instance cache */
    static protected $_instance = array();

    protected $_delayed = array();

    public $_transactionName;
    protected static $_forceMaster;

	const SELECT = 0;
	const DELETE = 1;
	const UPDATE = 2;
	const INSERT = 3;

	static protected $_ENCODE1 = array("'", '%', '"', "\n", "\r");
	static protected $_ENCODE2 = array("\\'", '\%', '\"', '\n', '\r');

		/** @ var array $_resources references to all mysql resources, so we can free them */
    protected $_resources;

	/**
     * build a new wrapper class around a database connection
     * @param mysqli $connection a mysqli connection to the database
     * @param int $master true to connect to a master database
     */
    protected function __construct(mysqli $connection, $master)
    {
        $this->_connection = $connection;
        $this->_master = $master;
        self::$_forceMaster = false;

        $this->_transactionName = null;
        $this->_effectedRows = null;
        $this->_lastResult = null;
		// set our default constraint type.  since we dont change state on
		// anything but POST requests nothing else needs constraints.
        $this->_unConstrained = true;
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST');
            $this->_unConstrained = false;
		// for database unit testing
        $this->_mockResultSet = array();
        $this->_namedMockResultSet = array();
        $this->_resources = array();
    }

	/**
	 * force all connections to the master
	 */
	public static function forceMaster()
	{
		self::$_forceMaster = true;
	}

	/**
	 * handles singleton database references
	 * @param string $databaseName the name of the database to connect to
	 * @param boolean $master true if we need a master connection
	 * @return DB
	 */
    public static function getConnection($databaseName, $master)
	{
		if (self::$_forceMaster)
			$master = true;
		$suffix = ($master) ? '-master' : '-slave';

		// instance cache
        if (isset(self::$_instance[$databaseName . $suffix]))
            return self::$_instance[$databaseName . $suffix];
        if (!self::$_log)
            self::$_log = Logger::getLogger('dblib');


		$start = microtime(true);
		// handle unit tests with no database connection
		if (self::$_unitTest)
			$connection = self::$_instance[$databaseName . $suffix] = new DB(null, $master);
		// read connection this time ...
		else
		{
			// init the mysqli connection...
			$handle = mysqli_init();
        	mysqli_options($handle, MYSQLI_OPT_CONNECT_TIMEOUT, 1);
        	$ctr = $success = 0;

			// map points to auth, auth contains login info
        	$auth = $GLOBALS['database-auth'][$GLOBALS['database-map'][$databaseName].$suffix];


			// connect and handle connection errors / retrys
			do
			{
				$success = mysqli_real_connect($handle,
					$auth['host'], $auth['username'], $auth['password'], $databaseName);
			}
			while (++$ctr < 3 && !$success);

			// not connected =(
			if (!$success)
				DB::ConnectionError($databaseName . $suffix);

			$connection = self::$_instance[$databaseName . $suffix] = new DB($handle, $master);
		}
        $end = microtime(true);

		// set the default constraints
        if (!$master)
            $connection->unConstrainEntities();

        self::$_log->info("new SQL connection to $databaseName{$suffix}, " . round(($end-$start),6) . ' sec');

        // for master connections, create a post render action to execute delayed SQL
        if ($master)
            Router::getInstance()->addPostRenderAction(new PostRenderSQL($connection));
        // for profiled SQL, create a post render action to log the sql
        else if (PROFILE)
            Router::getInstance()->addPostRenderAction(new LogProfile ($connection));

		return $connection;
	}

	/**
	 * enable unit test mode.  This allows mock result sets...
	 * @param boolean $mode
	 */
    public static function setTestMode($mode = true)
	{
        self::$_unitTest = $mode;
	}

	/**
     * @param string $name datbase name connection failed on
     */
    private static function ConnectionError($name)
    {
		Logger::getLogger('exception')->fatal('db connect error (' . $name . " connect err: \"" . mysqli_connect_error() . "\" connect errno: [" . mysqli_connect_errno() .']');

        if (FAIL_URL == 'EXCEPTION')
            throw new ConnectionException("db connect error: $name");

		die(header('Location: ' . FAIL_URL));
    }

	/**
     * @return string the mysql error string for the last error on this database
     *  connection
     */
    public function getLastError()
    {
        if ($this->_connection == null)
            return '(not connected)';
        return mysqli_errno($this->_connection) . ' ' . mysqli_error($this->_connection);
    }

	/**
     * @return integer the mysql error number for the last error on this database
     */
    public function getLastErrorNo()
    {
        if ($this->_connection == null)
            return 0;
        return mysqli_errno($this->_connection);
    }

	/**
	 * @return integer the last database insert id 
	 */
	public function getLastInsertId()
	{
        if ($this->_connection == null)
            return 0;
        $id = mysqli_insert_id($this->_connection);
		return $id;
	}

    /**
     * set all created entities (findEntities) to be UN constrained.
	 * constrained entities have change listeners and setter constraints
	 * only data that is being updated from user data should be constrained
     */
    public function unConstrainEntities()
    {
        $this->_unConstrained = true;
    }

     /**
     * set all created entities (findEntities) to be CONSTRAINED.
	 * constrained entities have change listeners and setter constraints
	 * only data that is being updated from user data should be constrained
     */
    public function constrainEntities()
    {
        $this->_unConstrained = false;
    }

	/**
	 * @return integer the number of rows that were returned in the last result-
	 *  set, update, insert or delete statement.
	 */
	public function getAffectedRows()
	{
        if ($this->_effectedRows === null)
        {
            if (!is_bool($this->_lastResult))
                $this->_effectedRows = mysqli_num_rows($this->_lastResult);
            else
                $this->_effectedRows = mysqli_affected_rows($this->_connection);
        }

		return $this->_effectedRows;
	}

	/**
     * start a new database transaction
     * @param string $transactionName a human readable name for the transaction
     * @return boolean true if the transaction was created successfully
     * @throws RuntimeException if a transaction was attempted on a slave database
     * @throws InvalidArgumentException if no transaction name was provided
     * @throws SQLException if a current transaction already exists, or creating
     *   the new transaction failed
     */
    public function beginTransaction($transactionName = null)
    {
		if ($transactionName == null)
			throw new InvalidArgumentException('transaction name required');
        if ($this->_master !== true)
			throw new RuntimeException('can not execute transactions on slave databases');
        // handle transaction state errors, allow nested transactions for testing..
        if ($this->_transactionName != null)// && !stristr($this->_transactionName, "test"))
            throw new SQLException('nested transactions not possible, ' .
                'current transaction: ' . $this->_transactionName);

        // allow test connections to succeed
        if ($this->_connection == null)
            return true;

		// begin the transaction
        self::$_log->trace('set transaction isolation level read uncommitted'); 
        $iresult = mysqli_query($this->_connection, 'set transaction isolation level read uncommitted'); 
        if (!$iresult)
            throw new SQLException('unaqueryble to set isolation level ' .
                $this->_transactionName .':'. mysqli_error($this->_connection));

        
        self::$_log->trace("START TRANSACTION");
        $iresult = mysqli_query($this->_connection, "START TRANSACTION");
        if (!$iresult)
            throw new SQLException('unable to begin database transaction: ' .
                $this->_transactionName .':'. mysqli_error($this->_connection));


        // log the transaction
		$this->_transactionName = $transactionName;
        self::$_log->debug("started new transaction $transactionName");
        return true;
    }

    /**
     * Commit all database modifications since the start of the current
     * transaction.  Ends the current running transaction.
     * @return boolean true if the commit succeeds
     * @throws InvalidArgumentException if no transaction is running
     * @throws SQLException if creating the new transaction failed
     */
    public function commitTransaction()
    {
        // make sure we have a transaction to commit
        if ($this->_transactionName == null)
			throw new InvalidArgumentException('no transaction running');

        // allow test connections to succeed
        if ($this->_connection == null)
            return true;

        // comit the transaction
        $iresult = mysqli_query($this->_connection, "COMMIT");
        if (!$iresult)
            throw new SQLException('unable to commit database transaction: ' .
                $this->_transactionName .':'. mysqli_error($this->_connection));

        // log transaction commited
        self::$_log->debug("commit database transaction: " .
            $this->_transactionName);
        $this->_transactionName = null;
        return true;
    }

    /**
     * Roll-back all database modifications since the start of the current
     * transaction.  End the current running transaction.
     * @return boolean true if the rollback succeeds
     * @throws InvalidArgumentException if no transaction is running
     * @throws SQLException if the rollback fails
     */
    public function rollBackTransaction()
    {
        if ($this->_transactionName == null)
			throw new InvalidArgumentException('transaction name required');

        // allow test connections to succeed
        if ($this->_connection == null)
            return true;

        $this->_transactionName = null;
        self::$_log->warn("ROLLBACK");
        $iresult = mysqli_query($this->_connection, 'ROLLBACK');
        if (!$iresult)
            throw new SQLException('unable to rollback database transaction: ' .
                $this->_transactionName .':'. mysqli_error($this->_connection));

        self::$_log->warn("rollback database transaction: " . $this->_transactionName);
        return $iresult;
    }

    /**
     * map a sql statement directly to an Entity.
     * <code>
	 * // TODO
     * </code>
     *
     * @param string $logName sql statements must have unique names for caching and logging
     * @param string $className the name of a mapped class to map the results into
     *   will return a list of those results, NOT an array.  be sure this class name
     *   implemented Mapped, otherwise a php fatal error will occur
     * @param string $selectStmt the sql select statement without the where clause
     * @param array $where ANDED sql values to match on
     *   array ('columnName' => 'columnValue', '')
     * @param string $predicate sql to append after the where clause, this
	 *   includes LIMIT, GROUP BY, HAVING, etc.  no filtering is performed
	 *   on this
     * @throws Exception if the select fails
     * @throws InvalidArgumentException if $className is not an instance of Mapped
     * @return ArrayList of entities.
     */
    public function findEntities($logName, $className, $selectStmt, array $where = null, $predicate = null)
    {
        // create the SQL and execute it
        if ($where != null)
        {
            $where2 = $this->createQuery($where, DB::SELECT);
            if ($where2 != '')
            {
                $selectStmt .= " WHERE $where2";
            }
        }

		if ($predicate != null)
			$selectStmt .= " $predicate";

        // if we dont have a mocked result set, query the database
        $rows = $this->popMockResultSet();
        $entityArray = array();
		// turn the resource into an entity
        if ($rows == null)
        {
            $queryResource = $this->doQuery($selectStmt, $logName);
            while ($row = mysqli_fetch_assoc($queryResource)) {
                $entityArray[] = $this->createEntity($className, $row);
			}
        }
        // turn mocked data into entity
        else
        {
            foreach ($rows as $row)
                $entityArray[] = $this->createEntity($className, $row);
        }

		// free allocated memory
		if (LIB_FREE_MEMORY && $queryResource instanceOf mysqli_result)
			mysqli_free_result ($queryResource);

        // return the result in an ArrayList
        $result = new ArrayList($className);
        return $result->setArray($entityArray);
    }

	/**
     * @param string $className the "name" of the class
     * @param array $row the database data to hydrate with
     * @return Entity the newly hydrated entity
     */
    protected function createEntity($className, array $row)
    {
        // unconstrained entities can pre-populate themselves
        if ($this->_unConstrained)
			return new $className(false, $row, false);

        // constrained entities may be in the old style, and have to call mapFromArray manually
        // TODO: fix these 
        $entity = new $className(true, $row, true);
		$entity->attach();
        return $entity;
    }


    /**
	 * <code>
	 * $stmt = 'REPLACE INTO user SET slug = ?
	 * $stmt = 'UPDATE user SET slug = ?
	 * $stmt = 'DELETE fROM user WHERE slug = ?
	 * $rows = selectStmt ('replace_into_slug', $stmt, array($slug));
	 * </code>
	 *
	 * @param string $logName the name of the sql being executed (for logging)
	 * @param string $stmt the sql statement, use '?' for replacements
	 * @param array $params an array of actual parameter values in order to be replaced in the query
     * @return mixed the return value from mysql_query()
	 */
	function sqlStmt($logName, $stmt, $params)
	{
        $stmt = $this->parseStmt($stmt, $params);

        // log the statement in the update query mock log
        if (stristr($stmt, 'update'))
            $this->pushMockUpdate('update', null);
        else if (stristr($stmt, 'insert'))
            $this->pushMockUpdate('insert', null);
        else if (stristr($stmt, 'delete'))
            $this->pushMockUpdate('delete', null);

        $result = $this->doQuery($stmt, $logName);

		if (LIB_FREE_MEMORY && $result instanceOf mysqli_result)
			mysqli_free_result ($result);
		
		return $result;
	}


    /**
     * exactly like a sqlStmt, except that it is delayed until AFTER page execution
     *
     * <code>
	 * $stmt = 'REPLACE INTO user SET slug = ?
	 * $stmt = 'UPDATE user SET slug = ?
	 * $stmt = 'DELETE fROM user WHERE slug = ?
	 * $rows = selectStmt ('replace_into_slug', $stmt,
     *  array(array('S', $slug));
	 * </code>
	 *
	 * @param string $logName the name of the sql being executed (for logging)
	 * @param string $stmt the sql statement, use '?' for replacements
	 * @param array $params an array of actual parameter values as arrays
	 *  bind paramaters, s-string, i-integer, d-double,
	 * u-html entetized data, c-digits with allowed sql comparators,
	 * z-black list protected string
	 * @param boolean $force force the SQL to execute (removes injection checking)
     * @return mixed the return value from mysql_query()
     *
     */
	function sqlDelayedStmt($logName, $stmt, $params)
    {
        $this->_delayed[] = array($logName, $stmt, $params);
    }

    /**
     * execute delayed sql updates
     */
    function execDelayed()
    {
        foreach ($this->_delayed as $stmt)
        {
            $this->sqlStmt($stmt[0], $stmt[1], $stmt[2]);
        }
    }


    /**
	 * <code>
	 * $stmt = 'SELECT * FROM user WHERE slug = ?'
	 * $rows = selectStmt ('get_user_by_slug', $stmt,
     *  array(Filter::filterParam('slug', Filter::ALPHA_NUMERIC_LOOSE));
	 * </code>
	 *
	 * @param string $logName the name of the sql being executed (for logging)
	 * @param string $stmt the sql statement, use '?' for replacements
	 * @param array $params the bind parameters (un named)
     * @return array an array of the results, or an empty array if there were no results
	 */
	function selectStmt($logName, $stmt, array $params = null)
	{
        // bind the parameters
        $query = $this->parseStmt($stmt, $params);

        // use mocked results sets
        $rows = $this->popMockResultSet();
		// do an actual query
        if ($rows == null)
        {
            $rows = array();
            $iresult = $this->doQuery($query, $logName);
            if ($iresult instanceOf mysqli_result)
                while($row = mysqli_fetch_assoc($iresult))
                    $rows[] = $row;
        }

		// free allocated memory
		if (LIB_FREE_MEMORY && $iresult instanceOf mysqli_result)
			mysqli_free_result ($iresult);

		return $rows;
	}



	/**
	 * NOTE: No cache is provided here.  Be sure to cache your SQL in your DAO.
	 *
	 * Client side bind params for SELECT
     * <code>
     * $where = array('userid' => Filter::numericFilter('userid'));
     * $rows = $db->select('load_all_user_data_by_id', 'SELECT * FROM users', $where);
     * </code>
     *
     * @param string $logName sql statements must have unique names for caching and logging
     * @param string $selectStmt the sql select statement without the where clause
     * @param array $where in the format array(column => value, ...)
     * @param string $predicate additional limit or order by clauses
     * @throws SQLException if the select fails
     * @return array of database rows
     */
    public function select($logName, $selectStmt, array $where = null, $predicate = '')
    {
		// append the where to the select
		$selectStmt .= (is_array($where)) ? ' WHERE ' . $this->createQuery($where, 0) : '';
		$selectStmt .= " $predicate";

		// get a mocked result!
        $resultArray = $this->popMockResultSet();
        if ($resultArray == null)
        {
            $resource = $this->doQuery($selectStmt, $logName);

            // return an array of rows
            $resultArray = array ();
            if ($resource instanceOf mysqli_result)
                while ($row = mysqli_fetch_assoc($resource))
                    $resultArray[] = $row;
			$this->_resources[] = $resource;
        }

		// free allocated memory
		if (LIB_FREE_MEMORY && $resource instanceOf mysqli_result)
			mysqli_free_result ($resource);

        return $resultArray;
    }

	public function delete($logName, $tableName, array $where)
    {
		// append the where to the select
		$deleteStmt = "DELETE FROM $tableName ";
		$deleteStmt .= (is_array($where)) ? ' WHERE ' . $this->createQuery($where, 0) : '';

		$result = $this->doQuery($deleteStmt, $logName);

       	return $result; 
    }



	/**
     * insert data into the database
     * <code>
     * $table = 'user';
     * Filter::validateInputNames(array('username', 'email', 'address', 'userid'));
     * $data = array(
     * array ('username' => Filter::alnumFilter('username')),
     * array ('email', => Filter::emailFilter('email')),
     * array ('address', => Filter::safeFilter('address'));
     * $db->doQuery ('insert_user', $table, $data);
     * </code>
     *
     * @param string $logName sql statements must have unique names for caching and logging
     * @param string $table the name of the database table to insert into
     * @param array $data the values to insert as an array in the format:
     *   array(array ('columnName', => $value), ...);
     * @return integer the newly inserted id for auto_inc pk (if available) or 0
     * @throws SQLException if the query fails
     */
    public function insert($logName, $table, array $data, $force = false)
    {
        // log the statement in the update query mock log
        $this->pushMockUpdate('insert', $data);

        // allow test connections to succeed
        if (self::$_unitTest)
            return 1;

	    $columnNames = join(', ', array_keys($data));
        $query = "INSERT INTO $table  ( " . $columnNames .
            ') VALUES ( ' . $this->createQuery($data, 3) . ')';
        $result = $this->doQuery($query, $logName, $force);

        $id = mysqli_insert_id($this->_connection);

		// free any memory if possible... 
		if (LIB_FREE_MEMORY && $result instanceOf mysqli_result)
			mysqli_free_result ($result);

		return $id;
    }

	/**
	 * update data in a table ?
	 * @param string $logName the queries name
	 * @param string $table the table to update?
	 * @param array $data data to update the table with the format array('column'=>value);
	 * @param array $where data to do the select by in the format array('column'=>value);
	 * @return boolean true if the query was successful
	 */
	public function update($logName, $table, array $data, array $where)
    {
        // handle unit tests
        $this->pushMockUpdate('update', $data);

        $query = "UPDATE $table SET " . $this->createQuery($data, 2) . ' WHERE ' . $this->createQuery($where, 0);
        $result = $this->doQuery($query, $logName);

		if (LIB_FREE_MEMORY && $result instanceOf mysqli_result)
			mysqli_free_result ($result);
		return $result;
    }


	/**
     * split "SELECT ? FROM ? ORDER BY id" into:
     * array => ('SELECT', '?', 'FROM', '?', 'ORDER BY id');
     * @param string $stmt the SQL query to split into components
     * @param array $params the values to replace
     * @return string the parsed sql query
     */
    protected function parseStmt($stmt, $params)
    {
		$paramCount = 0;
        $query = '';

		// loop over each part after each literal, append a filtered paramater
		$tok = strtok($stmt, '?');
		while ($tok !== false)
		{
            $query .= $tok;
            if (isset($params[$paramCount])) {
				if ($params[$paramCount][0] == '!')
				{
					$query .= substr($params[$paramCount], 1);
				}
				else
					$query .= '\'' . $this->encode($params[$paramCount]) . '\'';

                $paramCount++;
            }
			$tok = strtok('?');
        }

        // if the last character is a paramater, then add one more
		if (substr($stmt, -1) == '?' && count($params) > $paramCount)
		{
            self::$_log->debug('last char is ?, adding last param after parse...');
            self::$_log->info(print_r($params, true));
            self::$_log->info($query);
			if ($params[$paramCount][0] == '!')
                $query .= substr($params[$paramCount], 1);
			else
				$query .= '\'' . $this->encode($params[$paramCount]) . '\'';
            self::$_log->info($query);
            $paramCount++;
		}
        /*
		else if (substr($stmt, -1) == '?')
            self::$_log->debug('last char is ? but we got them all');
         */

        if (count($params) != $paramCount)
			throw new SQLException("Statement parameter count does not match passed parameter count: " . count($params) . " / $paramCount");

        return $query;
    }


	/**
     * take an array of data points and turn them into a sql statement
     * @param array $data the data as key value pairs ("column" => "value")
     * @param integer $type the query type, 0 = select, 1 = delete, 2 = update, 3 = insert
     */
    protected function createQuery($data, $type = 0)
    {
        $query = '';
		$i = 0;
		foreach ($data as $column => $value)
        {
			// append the paramater seperators...
            if ($i++ > 0)
				$query .= ($type >= 2) ? ', ' : ' AND ';

            // NULL
            //if ($value === null)
			//	$value = '!NULL';

            // If column name contains a space assume what is after the space is a custom operator (>, <=, IS NOT, REGEXP, etc.)
            $op = '=';
            if (preg_match('/^(.*?) (.*)$/', $column, $match)) {
                $column = $match[1];
                $op = $match[2];
            }

			// if we have multiple values for a column, we need an IN
			if (is_array($value))
			{
				//$query .= ($type == 2) ? "$column IN ('" . join("','", $value) . '\') ' : '(\'' . join("','", $value) . '\')';
				$query .= "$column IN ('" . join("','", $value) . '\') ';
			}
			else
			{
				if ($value == null)
					$query .= ($type == 3) ? 'NULL': "$column IS NULL";
				else if (isset($value[1]) && $value[0] == '!' && $value[1] == 'L')
					$query .= ($type == 3) ? substr($value, 1) : "$column " . substr($value, 1);
				else if (isset($value[0]) && $value[0] == '!')
					$query .= ($type == 3) ? substr($value, 1) : "$column $op " . substr($value, 1);
				else
					$query .= ($type == 3) ? '\'' . $this->encode($value) . '\'' : "$column $op '" . $this->encode($value) . '\' ';
			}
		}
		return $query;
	}


    /**
     * execute a query, time the query, log it and perform sql injection tests
	 *
     * @param string $query the SQL paramater
     * @param string $name the name of the query
     * @param boolean $force true to ignore injection test
     * @return boolean true if the query succeeds
     * @throw SQLException if the query fails
     */
    protected function doQuery($query, $name)
    {
		// log the query
        self::$_log->debug("DB.doQuery: $name");
		// reset counters
        $this->_lastResult = null;
        $this->_effectedRows = null;

        // if we are using mock results, then we are done =)
        if (self::$_unitTest)
            return true;

        // execute and time the query
        self::$_log->trace($query);
		// flich the counters for detailed profile data
		if (PROFILE)
        	mysqli_query($this->_connection, "FLUSH STATUS -- profile");
        $start = microtime(true);
        $this->_lastResult = mysqli_query($this->_connection, $query . " -- $name");
        $end = microtime(true);

        // exception handling
        if ($this->_lastResult != true)
        {
            if (mysqli_errno($this->_connection) == 1062)
                throw new DuplicateKeyException(mysqli_error($this->_connection));

            throw new SyntaxException("$query $name failed because: " .
                mysqli_error($this->_connection) . "errno: " . mysqli_errno($this->_connection));
        }

        // LOG PERFORMANCE, rows effected
        if (self::$_log->getLevel() <= Logger::DEBUG)
        {
            if (!is_bool($this->_lastResult))
                $this->_effectedRows = mysqli_num_rows($this->_lastResult);
            else
                $this->_effectedRows = mysqli_affected_rows($this->_connection);
            self::$_log->info("QUERY: $name returned/updated {$this->_effectedRows} rows, in sec: " .
                round(($end - $start), 6));
        }

        // get detailed query profile data
        if (PROFILE)
        {
            $profile = mysqli_query($this->_connection, "show session status where (Variable_name like 'Select%'  or Variable_name  like 'Sort%' or Variable_name like 'Created%') and Value > 0 -- profile");
            $meta = '';
            while ($row = mysqli_fetch_assoc($profile))
                $meta .= $row['Variable_name'] . ' = ' . $row['Value'] . ',  ';

            SqlProfile::getInstance()->profileQuery($query, $end - $start, $meta);
        }

        return $this->_lastResult;
    }

    /**
     * clear out all mock insert updates and deletes
     */
    public static function clearMockUpdates()
    {
        self::$_dbUpdates = array();
    }

    /**
     * clear out all mock result sets
     */
    public function clearMockResultSets()
    {
        $this->_mockResultSet = array();
    }

    /**
     * @param array $result add a new result set to be returned from the next SQL SELECT query
     * @return DB a reference to this class
     */
    public function pushMockResultSet(array $result = null, $namedQuery = null)
    {
        // only do this in test mode
        if (!self::$_unitTest)
            return;

        if ($namedQuery == null)
        {
            if (!isset($result[0]))
                array_unshift($this->_mockResultSet, array($result));
            else
                array_unshift($this->_mockResultSet, $result);
        }
        else
        {
            if (!isset($result[0]))
                array_unshift($this->_namedMockResultSet[$namedQuery], array($result));
            else
                array_unshift($this->_namedMockResultSet[$namedQuery], $result);

        }

        return $this;
    }

    /**
     * @return array the next result in the mock data set
     */
    protected function popMockResultSet($namedQuery = null)
    {
        if ($namedQuery != null && isset($this->_namedMockResultSet[$namedQuery]))
            $result = array_pop($this->_namedMockResultSet[$namedQuery]);
        else
            $result = array_pop($this->_mockResultSet);
        return $result;
    }

    /**
     * @param string type of update, insert / update / delete
     * @param array $update add a new update to the mocked updates array
     * @return DB a reference to this class
     */
    protected function pushMockUpdate($type, array $update = null)
    {
        // only do this in test mode
        if (!self::$_unitTest)
            return;

        if (!isset($update[0]) || $update == null)
        {
            array_unshift(self::$_dbUpdates, array($type));
        }
        else
        {
            array_unshift($update, $type);
            //$update[] = $type;
            array_unshift(self::$_dbUpdates, $update);
        }

        return $this;
    }

	/**
	 * mysqli_real_escape_string makes a RT to the DB for every call.  We avoid that here by doing our latin1
	 * encoding on the server.  This saves about 4ms per encoded item + lots of memory for the network call
	 * and DB load.
	 * @param string $input to be encoded
	 * @return string the encoded string
	 */
	public static function encode($input)
	{
		if(get_magic_quotes_gpc())
			$input = stripslashes ($input);
		return str_replace(self::$_ENCODE1, self::$_ENCODE2, $input);
	}

    /**
     * @return array the next result in the mock data set for inserts / updates / deletes
     */
    public static function popMockUpdate()
    {
        $result = array_pop(self::$_dbUpdates);
        return $result;
    }

	/**
	 * @return mysqli a mysql db connection
	 */
	public function getResource()
	{
		return $this->_connection;
	}
}
?>
