<?php

class HttpException extends RuntimeException
{
}

class HttpTimeoutException extends RuntimeException
{
}

class HttpResponse
{
    protected $_status;
    protected $_info;
    protected $_data;

    public function __construct($status,$info, $data){
        $this->_status = $status;
        $this->_info = $info;
        $this->_data = $data;
    }

    public function getStatus(){
        return $this->_status;
    }

    public function setStatus($status){
        $this->_status = $status;
    }

    public function getInfo(){
        return $this->_info;
    }

    public function setInfo($info){
        $this->_info = $info;
    }

    public function getData(){
        return $this->_data;
    }

    public function setData($data){
        $this->_data = $data;
    }
}

/**
 * A utility for requesting web pages and posting data to web pages
 *
 * @author cory
 */
class HttpRequest
{
	/**
	 * post data to a web page with GET and return the result
	 * @param string $url the url to post to
	 * @param array $data the data to post, key value pairs in the content head
	 *   parameter of the HTTP request
	 * @param string $optional_headers optional stuff to stick in the header, not
	 *   required
	 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
	 * @throws HttpException if a connection could not be established OR if data
	 *  could not be read.
	 * @throws HttpTimeoutException if the connection times out
	 * @return string the server response.
	 */
	public static function getData($url, $data, $timeout = 5, $optional_headers = null)
	{
		return self::doRequest('GET', $url, $data, $timeout, $optional_headers);
	}

	/**
	 * post data to a web page with GET and return the result
	 * @param string $url the url to post to
	 * @param array $data the data to post, key value pairs in the content head
	 *   parameter of the HTTP request
	 * @param string $optional_headers optional stuff to stick in the header, not
	 *   required
	 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
	 * @throws HttpException if a connection could not be established OR if data
	 *  could not be read.
	 * @throws HttpTimeoutException if the connection times out
	 * @return HttpResponse the server response.
	 */
	public static function getResponse($url, $data, $timeout = 5, $optional_headers = null)
	{
		return self::doRequest('GET', $url, $data, $timeout, $optional_headers,true);
	}

	/**
	 * post data to a web page with POST and return the result
	 * @param string $url the url to post to
	 * @param array $data the data to post, key value pairs in the content head
	 *   parameter of the HTTP request
	 * @param string $optional_headers optional stuff to stick in the header, not
	 *   required
	 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
	 * @throws HttpException if a connection could not be established OR if data
	 *  could not be read.
	 * @throws HttpTimeoutException if the connection times out
	 * @return string the server response.
	 */
	public static function postData($url, $data, $timeout = 5, $optional_headers = null)
	{
		return self::doRequest('POST', $url, $data, $timeout, $optional_headers);
	}

	/**
	 * post data to a web page with POST and return the result
	 * @param string $url the url to post to
	 * @param array $data the data to post, key value pairs in the content head
	 *   parameter of the HTTP request
	 * @param string $optional_headers optional stuff to stick in the header, not
	 *   required
	 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
	 * @throws HttpException if a connection could not be established OR if data
	 *  could not be read.
	 * @throws HttpTimeoutException if the connection times out
	 * @return HttpResponse the server response.
	 */
	public static function postResponse($url, $data, $timeout = 5, $optional_headers = null)
	{
		return self::doRequest('POST', $url, $data, $timeout, $optional_headers,true);
	}

	/**
	 * post data to a web page and return the result
	 * @param string $url the url to post to
	 * @param array $data the data to post, key value pairs in the content head
	 *   parameter of the HTTP request
	 * @param string $optional_headers optional stuff to stick in the header, not
	 *   required
	 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
	 * @throws HttpException if a connection could not be established OR if data
	 *  could not be read.
	 * @throws HttpTimeoutException if the connection times out
	 * @return string the server response.
	 */
	public static function doRequest($type, $url, $data, $timeout = 5, array $optional_headers = null, $return_object=false)
	{
		$log = Logger::getLogger('HttpRequest');
		$log->debug('starting HTTP post to, url: ' . $url);

		// build the post content paramater
		$content = '';
        if (is_array($data) && count($data))
        {
            foreach ($data as $key => $value)
            {
                $content .= "$key=$value&";
            }
            $content = trim($content, '&');
        }

		// set http paramaters
				//'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
		$params = array('http' => array(
				  'method' => $type,
				'header' => "Content-type: text/json\r\n" .
				  'Content-length:' . strlen($content) . "\r\n",
				  'content' => $content,
                  'timeout' => $timeout
			));
	#	$params['http']['header'] = "Content-type: application/x-www-form-urlencoded\r\n" .
	#			  'Content-length:' . strlen($content) . "\r\n";

		// add custom headers
		if (is_array($optional_headers))
		{
			foreach ($optional_headers as $key => $value)
				$params['http']['header'] .= "{$key}: {$value}\r\n";
		}

		// connect to the remote url
		$ctx = stream_context_create();//$params);
		$result = stream_context_set_option($ctx, 'ssl', 'allow_self_signed', true);
		$log->debug('stream context created');
		//$fp = fopen($url, 'rb', false, $ctx);
		$parts = explode('!', $url);
		$fp = stream_socket_client($parts[0], $err, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
		if (!$fp)
		{
			throw new HttpException("Unable to connect to [$url], $php_errormsg");
		}

		// read data
		$log->debug('stream connected');
		//stream_set_timeout($fp, $timeout);
		$url = $parts[1];
		$req = "$type {$url} HTTP/1.1\r\nHost: {$optional_headers['host']}\r\nUser-Agent: {$optional_headers['ua']}\r\nConnection: close\r\n\r\n";
		fputs($fp, $req);
		$response = @stream_get_contents($fp);
		$info = stream_get_meta_data($fp);
		fclose($fp);

		// throw an exception if the connection timed out
		if ($info['timed_out'])
		{
			throw new HttpException("Http connection timed out after $timeout seconds");
		}

		// throw an exception if data couldnot be read
		if ($response === false)
		{
			throw new HttpException("Unable to read data from $url, $php_errormsg");
		}

		// return the result
		$log->debug('stream data read, connection closed');
        if ($return_object){
            $obj = new HttpResponse(true,$info,$response);
            return $obj;
        } else {
    		return $response;
        }
	}
}
