<?hh //Partial
/**
 * @category PHP
 * @package util
 * @see set_error_handler
 * @author Cory Marsh
 * @version 1.2
 */

/**
 * write to syslog
 * <code>
 * $logger = new Syslogger();
 * $logger->send("message to send");
 * </code>
 * @author Cory
 * @version 2.0
 */
class Syslogger
{

	// the syslog server to log to
	protected $_logHost;
	protected $_logPort;
	// log socket to send syslog data to
	protected $_socket;


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

	// TODO: move to syslog function (less code to parse if syslog is not used)
	// syslog facilitys
	const SYSLOG_USER = 1;
	const SYSLOG_AUTH = 10;
	const SYSLOG_LOCAL0 = 16;
	const SYSLOG_LOCAL1 = 17;
	const SYSLOG_LOCAL2 = 18;
	const SYSLOG_LOCAL3 = 19;
	const SYSLOG_LOCAL4 = 20;
	const SYSLOG_LOCAL5 = 21;
	const SYSLOG_LOCAL6 = 22;
	const SYSLOG_LOCAL7 = 23;

	// syslog levels
	const SYSLOG_EMERG = 0;
	const SYSLOG_ALRET = 1;
	const SYSLOG_CRITICAL = 2;
	const SYSLOG_ERROR = 3;
	const SYSLOG_WARN = 4;
	const SYSLOG_NOTICE = 5;
	const SYSLOG_INFO = 6;
	const SYSLOG_DEBUG = 7;

	// the syslog level and facility
	protected $_facility = Syslogger::SYSLOG_LOCAL0;
	protected $_severity = Syslogger::SYSLOG_NOTICE;

	/**
     * volatile loggers only write to disk AFTER script completion.  This keeps disk IO to a minimum.
     * Suggested you override this default behavior with $GLOBALS['LOGNONVOLATILE'] = true; in development.
     *
     * @param string $name the unique logger name
     * @param boolean $volatile
     */
	public function __construct($logHost, $logPort)
	{
        $this->_socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        $this->setFacility($this->_facility, $this->_severity);
        $this->_logHost = $logHost;
        $this->_logPort = $logPort;
	}

    private function __destruct()
    {
        socket_close($this->_socket);
    }

    public function setFacility(int $facility, int $severity): string {
        // Get The Host
        if (isset($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        } elseif (function_exists('gethostname')) {
            $host = gethostname();
        } else {
            $host = 'unknown';
        }

        $this->_header = sprintf('<%d>%s %s ', ($facility * 8 + $severity), date('M i G:i:s '), $host);
        return $this->_header;
    }

	/**
	 * send a message to disk, and/or the syslog.
	 *
	 * @param integer $level the logging level for the message
	 * @param string $message the messsage to log
	 * @return nothing
	 */
	public function send(string $message): void
	{
        $message = $this->_header . substr($message, 0, 990);
        socket_sendto($this->_socket, $this->_header . $message, strlen($message), 0, $this->_logHost, $this->_logPort);
	}
}

$l = new Syslogger("localhost", 514);
$l->send("this is a message");

