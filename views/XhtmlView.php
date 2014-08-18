<?hh
/**
 * NEED GNU LICENSE HERE 
 */
if (!defined('VIEW_CACHE_DIR')) define ('VIEW_CACHE_DIR', '');
if (!defined('LIB_VIEW_CACHE_ENABLE')) define ('LIB_VIEW_CACHE_ENABLE', LIB_CACHE_ENABLE);
if (!defined('VIEW_DIR')) define ('VIEW_DIR', getcwd());

/**
 * @param integer $time unix timestamp to format
 * @return string
 */
function timeAgo($time)
{
    $diff = time() - $time;
    if ($diff > 86400 * 2)
        $data = intval($diff / 86400) . ' days ago'; 
    else if ($diff > 86400)
        $data = '1 day ago'; 
    else if ($diff > 7200)
        $data = intval($diff / 3600) . ' hours ago'; 
    else if ($diff > 3600)
        $data = '1 hour ago'; 
    else if ($diff > 60)
        $data = intval($diff / 60) . ' minutes ago'; 
    else 
        $data = '1 minute ago';
    return $data;
}


/**
 * Turn an XHTML view to an optomized php file
 * 
 * WTF with scripts ?
 *
 * @package views
 * @author Cory Marsh
 */
class XhtmlView
{
    private $root = array();
    private $_view;
    private $_forceCompile = false;
    private $_source = '';
    private $_script = '';
    private $_parser = null;

	private static $_instance = array();

	/**
	 * get a singleton reference to a view.   This is helpful when multiple modules
	 * need to share a view
	 * @param string $viewFile 
	 * @return XhtmlView the singleton
	 */
	public static function getView($viewFile)
	{
		if (!isset(XhtmlView::$_instance[$viewFile]))
				XhtmlView::$_instance[$viewFile] = new XhtmlView($viewFile);
		return XhtmlView::$_instance[$viewFile];
	}

	/**
	 * create a new view into the passed file.   The filepath may be relative or absolute.  Absolute
	 * paths resolve faster in PHP and require fewer "stat(2)"s
	 *
	 * @param string $viewFile the path (relative or absolute) to the xhtml view
	 */
	public function __construct($viewFile)
    {
        //$this->root = array();
        $this->_view = $viewFile;
        if ($viewFile[0] == '/')
            $this->_source = $viewFile;
        else
            $this->_source = VIEW_DIR . '/' . $viewFile;
        //echo "<pre>" . $this->_source . "</pre>";
    }

    /**
     * normally the views are stat(2)ed and if the source file is newer than the compiled version, then
     */
    public function forceCompile()
    {
        $this->_forceCompile = true;
    }

    /**
     * append text inside a <script type="text/javascript"></script>, at the end of the document
     * @param type $script 
     * @return XhtmlView a reference to $this
     */
    public function appendScript($script)
    {
        $this->_script .= $script;
        return $this;
    }

    /**
     * @param String $id the rogue:id value to replace
     * @param String $content the content to replace it with
     * @return XhtmlView a reference to $this
     */
    public function add($id, $content, $append = false)
    {
		if ($append && isset($this->root[$id]))
        	$this->root[$id] .= $content;
		else
        	$this->root[$id] = $content;
        return $this;
    }

    public function get($id)
    {
        if (isset($this->root[$id]))
            return $this->root[$id];
        return '';
    }

    /**
     *
     * @param string $viewFile the path (relative from VIEW_DIR or absolute) to the xhtml view
     */
    public function setViewFile($viewFile)
    {
        $this->_view = $viewFile;
		if ($viewFile[0] == '/')
            $this->_source = $viewFile;
        else
            $this->_source = VIEW_DIR . '/' . $viewFile;
    }

    /**
     * Render a compiled view.   if the view is not already compiled or it has cahnged on disk, compile it now
     *
     * This method requires 2 stats per page view to see if the view has changed and needs recompiling.
     * We could set this to ignore in production and always use compiled views if we compile before deployment.
     *
     * @return string the rendered view
     */
    public function render($elm = "body", $panel = null)
    {
        $ln = Text::getLanguage();
        $log = Logger::getLogger('init');

        //$ver = (isset($_COOKIE['A']) && $_COOKIE['A'] != 'A') ? 'B' : 'A';
		// hard code to ver A
		$ver = 'A';
        $rendered = VIEW_CACHE_DIR . "/" . $this->_view . "-{$elm}-{$panel}-$ln-$ver";
        $st2 = stat($rendered);
        $st1 = stat($this->_source);
		//echo "<pre>\n";
		//goto foo;
        // compile if not cached, or first compile
        if (!$st1)
        {
            $log->fatal("can not find view file: " . $this->_source);
            return '';
        }
		// always compile? not ever compiled?  source updated ?
        if (!LIB_VIEW_CACHE_ENABLE || !$st2 || intval($st1['mtime']) > intval($st2['mtime']))
        {

            $log->info($this->_view . " first compile, cache disabled, or stale");
            require_once ROGUE_DIR . '/views/XhtmlParser.php';
            $this->_parser = new XhtmlParser();
            $this->_parser->parse($this->_view, $rendered, strtoupper($elm), $panel);
		}
		
        // TODO: move to Skin inject a script variable, this really should be in the Skin ....
        if ($this->_script != '') {
			die("FIX THIS SCRIPT INJECTION");
            $this->root['script'] = '<script type="text/javascript">'.$this->_script.'</script>';
		}

        // default to display an error
        $result = '<pre>no compiled view found, or compiler error ' . VIEW_CACHE_DIR . '/' . $this->_view . "</pre>";
        // once the require runs, its like executing the script in the context of this object
        //echo "<pre>DEBUG OUTPUT:\n";
        //print_r($this);
        //echo "</pre>";
		//foo:;
        include($rendered);

        return $result;
    }


	/**
	 * @return XhtmlParser  if we had to parse the view
	 */
	public function getParser()
	{
		return $this->_parser;
	}
}
