<?hh

/**
 * @category PHP
 * @package util
 * @see set_error_handler
 * @author Cory Marsh
 * @version 1.2
 */


/**
 * take standard PHP errors and redirect them to the Rogue Log
 * you can disable by setting the $GLOBAL['NoLoggerHandler'] or by
 * calling Logger::enableErrorHandler(false);
 */
function NullHandler(int $errno, string $errstr, string $errfile, int $errline)
{
	return true;
}

function LoggerHandler(int $errno, string $errstr, string $errfile, int $errline)
{
	// if there is a problem with the Logger, we dont want to create a recursive loop here
	set_error_handler('NullHandler');
	$message = "$errstr on $errfile:$errline";
	switch ($errno)
	{
		case E_USER_ERROR:
		case E_CORE_ERROR:
		case E_PARSE:
		case E_COMPILE_WARNING:
		case E_USER_ERROR:
		default:
			Logger::getLogger('phperror')->error($message);
			break;
		case E_WARNING:
		case E_CORE_WARNING:
		case E_RECOVERABLE_ERROR:
		case E_USER_WARNING:
		case E_NOTICE:
		case E_USER_NOTICE:
			Logger::getLogger('phperror')->warn($message);
	}
	return true;
}

if (!isset($GLOBALS['NoLoggerHandler']) || $GLOBALS['NoLoggerHandler'] !== true)
{

	set_error_handler('LoggerHandler');
}


// default the timezone to Boise (Rouge PHP's home!)
$tz = date_default_timezone_get();
if (!$tz)
    date_default_timezone_set('America/Boise');

/**
 * logger, similar to Log4J.
 * <code>
 * $log = Logger::getLogger('logName');
 * $log->debug('This is a debug message');
 * </code>
 * @author Cory
 * @version 2.0
 */
class Logger
{
	// the log handle, and name
	protected $_logName;
	// min error level to save
	protected $_logLevel;
	// the format strings forthe log levels
	protected $_formatStr;
	// bool, true saves logs only at script exit
	protected $_volatile;

	// the syslog server to log to
	protected static $_logHost;
	// log file to log to
	protected static $_logFile;
    // if the logs have been dumped yet
    protected static $_written = false;

	protected static $_formats = array();
	// all loggers
	protected static $_loggers = array();
	// all log messages for this logger
	protected static $_entries = array();
	// logger creation time
	protected static $_creationTime = null;



	// log levels
	const DISABLE = -1;
	const TRACE = 0;
	const DEBUG = 1;
	const INFO = 2;
	const WARN = 3;
	const ERROR = 4;
	const FATAL = 5;
	const SECURITY = 6;
	const NONE = 98;
	const VOLATILE = 99;

	/**
     * volatile loggers only write to disk AFTER script completion.  This keeps disk IO to a minimum.
     * Suggested you override this default behavior with $GLOBALS['LOGNONVOLATILE'] = true; in development.
     *
     * @param string $name the unique logger name
     * @param boolean $volatile
     */
	private function __construct(string $name, bool $volatile = false)
	{
        $name = strtolower($name);
		$this->_logName = $name;
		$this->_formatStr = array();


        // store the time the first logger is created
		if (self::$_creationTime == null)
			self::$_creationTime = microtime(true);


        // set the minimum log level for this logger
        if (isset($GLOBALS['LOGLEVEL-' . $name]))
			$this->_logLevel = $GLOBALS['LOGLEVEL-' . $name];
		else if (isset($GLOBALS['LOGLEVEL']))
			$this->_logLevel = $GLOBALS['LOGLEVEL'];
        else
            $this->_logLevel = Logger::FATAL;


        // override volatile setting with global override
		$this->_volatile = $volatile;

		if (isset($GLOBALS['LOGNONVOLATILE']) && $GLOBALS['LOGNONVOLATILE'])
			$this->_volatile = false;
		if (!$this->_volatile)
        {
			$this->connectStorage();
        }


		// select the correct log format for this named logger, or use the default
		if (isset($GLOBALS['LOGFORMAT-' . $name]))
			$format = $GLOBALS['LOGFORMAT-' . $name];
		else if (isset($GLOBALS['LOGFORMAT']))
			$format = $GLOBALS['LOGFORMAT'];
		else
			$format = '[%w]: [%I:%r:%n:%p]: %m';

		// setup the log formats for each level
		$this->_formatStr[Logger::TRACE] = $format;
		$this->_formatStr[Logger::DEBUG] = $format;
		$this->_formatStr[Logger::INFO] = $format;
		$this->_formatStr[Logger::WARN] = $format;
		$this->_formatStr[Logger::ERROR] = $format;
		$this->_formatStr[Logger::FATAL] = $format;
		if (isset($GLOBALS['LOGFORMAT:TRACE']))
			$this->_formatStr[Logger::TRACE] = $GLOBALS['LOGFORMAT:TRACE'];
		if (isset($GLOBALS['LOGFORMAT:DEBUG']))
			$this->_formatStr[Logger::DEBUG] = $GLOBALS['LOGFORMAT:DEBUG'];
		if (isset($GLOBALS['LOGFORMAT:INFO']))
			$this->_formatStr[Logger::INFO] = $GLOBALS['LOGFORMAT:INFO'];
		if (isset($GLOBALS['LOGFORMAT:WARN']))
			$this->_formatStr[Logger::WARN] = $GLOBALS['LOGFORMAT:WARN'];
		if (isset($GLOBALS['LOGFORMAT:ERROR']))
			$this->_formatStr[Logger::ERROR] = $GLOBALS['LOGFORMAT:ERROR'];
		if (isset($GLOBALS['LOGFORMAT:FATAL']))
			$this->_formatStr[Logger::FATAL] = $GLOBALS['LOGFORMAT:FATAL'];
	}

	/**
     * write the log data to disk on scipt end
	 **/
	public function __destruct()
	{
        if (function_exists('NullHandler'))
            set_error_handler('NullHandler');

		// save the log to persitant storage
		if ($this->_volatile && !self::$_written)
		{
            //echo "<pre>connect\n</pre>";
			$this->connectStorage();
            //echo "<pre>write\n</pre>";
			// loop over each
			do
			{
				// TODO: fix this, should look at each loggers level, not the global level
				// get the next message
				$message = array_shift(Logger::$_entries);
				if ($message == null || $message[1] < $GLOBALS['LOGLEVEL'])
					continue;

				// write to disk
				if (Logger::$_logFile)
					fwrite(Logger::$_logFile, $GLOBALS['cuser'] . ' ' . $message[2] . "\n");
                /*
                SYSLOG
				if (Logger::$_socket)
				{
					$data = $this->syslogize(substr($message[2], 0, 990));
					socket_sendto(Logger::$_socket, $data, strlen($data), 0, $GLOBALS['LOGHOST'], 514);
				}
                */
			}
			while ($message != null);
            //echo "<pre>wrote\n</pre>";
            //
            // sometimes we can hit the descructor twice (if writing the log creates a warning)
            Logger::$_logFile = false;
            self::$_written = true;
			Logger::$_entries = array();
		}
	}

	/**
	 * connect to persistant storage for saving log messages.
	 * connects to the logfile, and the remote syslog udp server IF they are
	 * configured.
	 * MUST call this method before writing to disk as $_logFile or sending
	 * data to $_socket;
	 * @since 1.2
	 */
	private function connectStorage(): void
	{
		// open the log file
		if (!isset (Logger::$_logFile) && isset($GLOBALS['LOGFILE']))
			Logger::$_logFile = fopen($GLOBALS['LOGFILE'], 'a+');

		// SYSLOG
		//if (!isset (Logger::$_socket) && isset($GLOBALS['LOGHOST']))
	//		Logger::$_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
	}

	/**
	 * static singleton constructor.  Always create a rootLogger if one does not exist yet
	 * @param string $name the logger name (similar to log facility, will be added to each log message
	 * @param boolean $volatile true if this is a volaitle logger
	 *  (write's log as script end, not when messages arrive)
	 * @return Logger the new logger, ready for logging.
	 */
	public static function getLogger(string $name, bool $volatile = true)
	{
		// return an already constructed logger of this name (singleton-ish)
		if (isset(Logger::$_loggers[$name]))
			return Logger::$_loggers[$name];

		return Logger::$_loggers[$name] = new Logger($name, $volatile);
	}

	/**
	 * TODO: add this to the skin for admins
	 * get an HTML formatted version of the log.  Gives you a div#logger.
	 * styles in bodyspace/styles.css can pretty print the HTML.
	 * @return string hidden HTML display of the log
	 * @since 1.2
	 */
	public static function getWebLog(): string
	{
		$output = '<div id="logger">';

		foreach (Logger::$_entries as $entry)
		{
			$entry[2] = str_replace("\n", '<br/>', $entry[2]);
			if ($entry[1] === Logger::ERROR || $entry[1] === Logger::FATAL)
				$output .= "<br><span class='red'>$entry[2]</span>\n";
			else if ($entry[1] == Logger::WARN)
				$output .= "<br><span class='yellow'>$entry[2]</span>\n";
			else if ($entry[1] == Logger::INFO)
				$output .= "<br><span class='green'>$entry[2]</span>\n";
			else if ($entry[1] == Logger::DEBUG)
				$output .= "<br><span class='debug'>$entry[2]</span>\n";
			else if ($entry[1] == Logger::TRACE)
				$output .= "<br><span class='light'>$entry[2]</span>\n";
			else
				$output .= "<br>$entry[2]\n";
		}
		/*
		if ($diagnostics) {
			foreach ($diagnostics as $key => $value)
				$output .= "<br><span style='color: blue'>$key in: $value secs</span>\n";
		}
		*/
		$output .= '</div>';
		return $output;
	}

	/**
	 * set the minimum log level for the logger to actually log.
	 * available log levels are Logger::FATAL, ERROR, WARN, INFO, DEBUG, TRACE
	 * $log->_setLevel(Logger::$ERROR);
	 * $log->_warn('this will not log');
	 * $log->_error('this will log');
	 * @param integer $level one of the const logging levels
	 * @see Logger logger const settings
	 */
	public function setLevel(int $level = Logger::ERROR): void
	{
		$this->_logLevel = $level;
	}

	/**
	 * set the logging format.  The default is: {%d [%n:%p] %m}
	 * unless the $GLOBAL variable LOGFORMAT is set, then that will be used instead
	 * setting this method will override the LOGFORMAT, but this can be overriden
	 * with the FORCELOGFORMAT $GLOBAL.
	 *
	 *
	 * Substitute symbol
	 * %d{dd/MM/yy HH:MM:ss } Date
	 * %t{HH:MM:ss } Time
	 * %w the logger instance id (sesison tracking)
	 * %r Milliseconds since logger was created
	 * %n logger name
	 * %p Level
	 * %m user-defined message
	 *
	 * %S Server Name
	 * %s PHP script name
	 * %I IP address of browser
	 * %H the request url
	 * %U HTTP user agent
	 * %Ccookie_name COOKIE content
	 * %u mybb2 cookie user slug
	 * %i mybb2 cookie user id
	 *
	 * %f File name
	 * %F complete File path
	 * %L Line number
	 * %M Method name
	 * %A Method arguments
	 * %B a formatted backtrace
	 * Caution: %f, %F, %L, %M, %A are slow formats
	 * @param string $format the format stringto set forthe logger
	 * @param integer $level the logging level to set the format for
	 * @return nothing
	 */
	public function setFormat(string $format = '%d [%n:%p] %m', bool $level = false): void
	{
		// TODO: move to array config
		if ($level == false)
		{
			$this->_formatStr[Logger::TRACE] = $format;
			$this->_formatStr[Logger::DEBUG] = $format;
			$this->_formatStr[Logger::INFO] = $format;
			$this->_formatStr[Logger::WARN] = $format;
			$this->_formatStr[Logger::ERROR] = $format;
			$this->_formatStr[Logger::FATAL] = $format;
		}
		else
			$this->_formatStr[$level] = $format;
	}

	/**
	 * return the current logging level
	 * @return integer the logging level as an integer
	 */
	public function getLevel(): int
	{
		return $this->_logLevel;
	}

	/**
	 * return the actual logging entries in the format:
	 * array (facility, level, message);
	 * @return array the log entries. each entry is of the form:
     *  array((string)logName, (int)logLevel, (string)message)
	 */
	public function getEntries(): array
	{
		return Logger::$_entries;
	}

	/**
	 * add a log line of value TRACE to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function trace(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::INFO)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'trace', Logger::TRACE);

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, (int)Logger::TRACE, $message);
		$this->dispatch(Logger::TRACE, $message);
		return false;
	}


	/**
	 * add a log line of value DEBUG to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function debug(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::DEBUG)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'debug', Logger::DEBUG);

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, Logger::DEBUG, $message);
		$this->dispatch(Logger::DEBUG, $message);
		return false;
	}

	/**
	 * add a log line of value INFO to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function info(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::INFO)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'info', Logger::INFO);
		// add the log line to our entries

		Logger::$_entries[] = array($this->_logName, Logger::INFO, $message);
		$this->dispatch(Logger::INFO, $message);
		return false;
	}

	/**
	 * add a log line of value WARN to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function warn(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::WARN)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'warn', Logger::WARN);

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, Logger::WARN, $message);
		$this->dispatch(Logger::WARN, $message);
		return false;
	}

	/**
	 * add a log line of value ERROR to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function error(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::ERROR)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'error', Logger::ERROR);

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, Logger::ERROR, $message);
		$this->dispatch(Logger::ERROR, $message);
		return false;
	}

	/**
	 * add a log line of value FATAL to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function fatal(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::FATAL)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'fatal', Logger::FATAL);

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, Logger::FATAL, $message);
		$this->dispatch(Logger::FATAL, $message);
		return false;
	}

	/**
	 * add a log line of value SECURITY to the log journal
	 * @param string $message the message to log
	 * @return false;
	 */
	public function security(string $message): bool
	{
		// don't log if the log level is too low
		if ($this->_logLevel > Logger::SECURITY)
			return false;

		// format the mesage
		$message = $this->msgFormat($message, 'security', Logger::SECURITY);
		$message .= print_r(debug_backtrace(0, 5));

		// add the log line to our entries
		Logger::$_entries[] = array($this->_logName, Logger::SECURITY, $message);
		$this->dispatch(Logger::SECURITY, $message);
		return false;
	}



	/**
	 * send a message to disk, and/or the syslog.
	 *
	 * @param integer $level the logging level for the message
	 * @param string $message the messsage to log
	 * @return nothing
	 */
	private function dispatch(int $level, string $message): void
	{
		// dont' write out volitile logger data
		if ($this->_volatile) {
			return;
		}
        set_error_handler('NullHandler');

		// send log data to the file
		if (isset(Logger::$_logFile))
        {
			fwrite(Logger::$_logFile, $message . "\n");;
        }

        // Dispatch to SYSLOG here
		set_error_handler('LoggerHandler');
	}

	
	/**
	 Substitute symbol
	%d{dd/MM/yy HH:MM:ss } Date
	%t{HH:MM:ss } Time
	%w the logger instance id (sesison tracking)
	%r Milliseconds since logger was created
	%n logger name
	%p Level
	%m user-defined message

	%S Server Name
	%s PHP script name
	%I IP address of browser
	%H the request url
	%U HTTP user agent
	%Ccookie_name COOKIE content
	%u mybb2 cookie user slug
	%i mybb2 cookie user id

	%f File name
	%F complete File path
	%L Line number
	%M Method name
	%A Method arguments
	%B a formatted backtrace

	%% individual percentage sign
	Caution: %f, %F, %L, %M, %A slow down program run!
	 */
	private function msgFormat(string $message, string $level, int $levelNum): string
	{
		// TODO: important omtomization, only run the format 1x
		// then sprintf the timestamp and message into the output

		// get the log format to use, and break it up into % tokens
        $fmt = $this->_formatStr[$levelNum];
        if (!isset(self::$_formats[$fmt]))
        {
            $cacheable = true;
            $token = strtok($fmt, '%');

            $line = '';
            $bt = false;
            // loop over all tokens
            while ($token !== false)
            {
                // the first char in the token is the token type (this is right after the %)
                $type = $token[0];
                switch ($type)
                {
                    case 'd':
                        $line .= date('Y/m/d H:i:s');
                        break;
                    case 't':
                        $line .= date('H:i:s');
                        break;
                    case 'r':
                        $line .= '%s';//round ((microtime(true) - self::$_creationTime), 6);
                        break;
                    case 'w':
                        $line .= getmypid();
                        break;
                    case 'p':
                        $line .= $level;
                        break;
                    case 'n':
                        $line .= substr($this->_logName, 0, 11);
                        break;
                    case 'm':
                        $line .= '%s';//$message;
                        break;
                    case 'S':
                        if (isset($_SERVER['SERVER_NAME']))
                            $line .= $_SERVER['SERVER_NAME'];
                        break;
                    case 's':
                        if (isset($_SERVER['SCRIPT_FILENAME']))
                            $line .= $_SERVER['SCRIPT_FILENAME'];
                        break;
                    case 'U':
                        if (isset($_SERVER['HTTP_USER_AGENT']))
                            $line .= $_SERVER['HTTP_USER_AGENT'];
                        break;
                    case 'I':
                        if (isset($_SERVER['REMOTE_ADDR']))
                            $line .= $_SERVER['REMOTE_ADDR'];
                        else
                            $line .= 'unknownaddr';
                        break;
                    case 'H':
                        if (isset($_SERVER['REQUEST_URI']))
                            $line .= $_SERVER['REQUEST_URI'];
                        break;
                    case 'C':
                        if (isset($_COOKIE[substr($token, 1)]))
                            $line .= $_COOKIE[substr($token, 1)];
                        else
                            $line .= 'na';
                        break;
                    case 'u':
                        break;
                    case 'f':
                        $cacheable = false;
                        if ($bt === false) {
							$bt = debug_backtrace();
							$bt = $bt[1];
						}
                        $paths = explode('/', $bt['file']);
                        $line .= array_pop($paths);
                        break;
                    case 'F':
                        $cacheable = false;
                        if ($bt === false) {
							$bt = debug_backtrace();
							$bt = $bt[1];
						}
                        $line .= $bt['file'];
                        break;
                    case 'L':
                    case 'M':
                        $cacheable = false;
                        if ($bt === false) {
							$bt = debug_backtrace();
							$bt = $bt[1];
						}
                        $line .= $bt['line'];
                        break;
                    case 'A':
                        $cacheable = false;
                        //$bt = debug_backtrace();
						$bt = debug_backtrace();
						$bt = $bt[1];
                        $line .= var_export($bt[2]['args'], true);
                        break;
                    case 'B':
                        $cacheable = false;
                        $line .= $this->formatBT();
                        break;
                    case 'v':
                        if (isset($_SERVER['HTTP_HOST'])) {
                            $line .= $_SERVER['HTTP_HOST'];
                        } else {
                            $line .= 'unknownhost';
                        }
                        break;
                    default:
                        $line .= $type;
                        break;
                }
                // append anything that is not a symbol as literal text to the string
                if (isset($token[1]))
                    $line .= substr($token, 1);

                // get the next token
                $token = strtok('%');
            }
            if ($cacheable)
                self::$_formats[$fmt] = $line;
        }
        else
        {
            $line = self::$_formats[$fmt];
        }

		// return the formatted string
		return sprintf($line, round ((microtime(true) - self::$_creationTime), 6), $message);
	}

	/**
	 * get the caller information through a backtrace
	 * @return string the backtrace caller that called the log message (2 deep)
	 */
	private function getBtCaller(): array
	{
		$bt = debug_backtrace();
		return $bt[2];
	}

	/**
	 * format a backtrace and return the result as a string.  Each function call is
	 * displayed on a newline.
	 * @return string a stack trace, each stack level is separated by a newline.
	 */
	private function formatBT(): string
	{
		// get the backtrace
		$bt = debug_backtrace();
		$log = '';

		// loop over the back trace elements EXCEPT for the call to formatBT()
		for($i = 2, $m = count($bt); $i < $m; $i++)
		{
			$log .= "\t";
			// the fine, lineno, function
			$line = $bt[$i];

			if(isset($line['file']))
				$log .= $line['file'];

			if(isset($line['line'] ))
				$log .= ' line:[' . $line['line'] . '] ';

			if(isset($line['function'] ))
				$log .= ' func: ' . $line['function'];
			$log .= "\n";
		}

		// return the list of function calls
		return $log;
	}

    /**
     * enable or disable the global error handler.  This handler puts the PHP Errors into
     * the Rogue Log.
     * @param boolean $enable true to enable error handling (Default) or false to disable
     */
    public static function enableErrorHandler(bool $enable = true)
    {
        $GLOBALS['NoLoggerHandler'] = $enable;
    }
}

