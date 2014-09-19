<?hh // partial
/* ex: set tags=../php.tags: */
require_once ROGUE_DIR . '/i18n/Text.php';

/**
 * This class renders the head and footer.   It "wraps" the content in a "skin" 
 */
class Skin
{
    /* @var string $_script the text markup of <Script src=""> tags */
	protected $_script = '';
	protected $_scriptList;

    /* @var string $_code javascript code to place inside a <script> tag in the footer */
	protected $_code = '';

    /* @var string $_setupJS javascript code to place inside a <script> tag in the TOP footer */
	protected $_setupJS = '';

    /* @var array $_style an array of css links */
	protected $_style;
    /* @var string $_title the <title> tag value */
	protected $_title = '';
	public $_shareTitle = '';

	/* the skin content */
	public $content = '';
    /* @var string $_description the <meta name="description"> tag value */
	protected $_description = '';
	public $_shareDescription = '';
    /* $var Skin $_instance singleton */
	protected static $_instance = null;
    /* @var boolean $_render true if the skin has been rendered */
	private $_render = false;
    /* @var boolean $_waitForLoad true if the skin should wait for load methods before rendering the <head> */
	private $_waitForLoad = false;
    /* $var XhtmlView $_view the actual XhtmlView instance */
    private $_view;
	private $_error;
    /* $var String $_ab the ab test version.  default A */
	protected $_ab = 'A';
    private $_canonical;


    /* $var array $_header list links in the header */
    private $_header = array();
    /* $var array $_menu list links in the menu */
    private $_menu = array();
	/* the skin to render */
	private string $_viewFile;

    /**
     * create the Skin and set the default skin 
     */
    private function __construct($defaultSkin = 'defaultSkin.xhtml')
	{
		$this->_viewFile = $defaultSkin;
        $this->_view = new XhtmlView($this->_viewFile);
		$this->_error = false;
        

        // create header links based on who is logged in
        // the vendor header link  
        $this->_header[] = array('class' => 'default',
                'name' => 'default',
                'href' => '/default',
                'title' => 'default title');

        $this->_style = array();
        $this->_scriptList = array();
    }
	


    /**
     * this method is called after all modules are created but before they have loaded their data.
     * If non of the modules have called Skin::getInstance()->waitForLoad(), then the <head> is rendered
     * first.  This is the case when cookies are not set.
     * 
     * If the skin is instructed to "waitForLoad()" then this method will do nothing, and will be called
     * again AFTER the loadData() methods.  During the second call, this method will FORCE the <head> render.
     * 
     * @see waitForLoad()
     * @param boolean $force  true if we are forcing the <head> rednder regardless of the waitForLoad setting
     */
	public function renderHead(bool $force = false, bool $returnResult = false): string
	{
        // make sure we only render once, if we should wait for load and this is before
		// load has happened, then dont render
		$doc = '';
		if (!$this->_render && (!$this->_waitForLoad || $force))
		{
            $doc = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">';

            $this->_view->add('language', Text::getLanguage());
            $this->_view->add('title', $this->_title);
            $this->_view->add('description', $this->_description);
            $this->_view->add('GOOGLE_ANALYTICS', GOOGLE_ANALYTICS);
            $this->_view->add('htmlver', HTML_VER);
            $this->_view->add('dbver', DB_VER);
            $this->_view->add('style', $this->_style);
            // default canonical page
            if (!$this->_canonical) { $this->_canonical = str_replace(array(' ', '%20', '%26'), array('+', '+', 'and'), rtrim($_SERVER['REQUEST_URI'], '/')); }
            $this->_view->add('canonical', 'http://' . DOMAIN . $this->_canonical);
            $this->_view->add('staticdomain', 'http://' . STATICDOMAIN);
            $this->_view->add('domain', 'http://' . DOMAIN);
            $this->_view->add('sharetitle', $this->_shareTitle);
            $this->_view->add('sharedescription', $this->_shareDescription);
            $this->_view->add('abver', $this->_ab);
            $this->_view->add('mobile', $GLOBALS['MOBILE']); // from Mobile page handler

			if (!$returnResult) {
            	echo $doc;
				echo $this->_view->render('head', 'head');
				$this->_render = true;
            	flush();
				return '';
			}
			else {
				$doc .= $this->_view->render('head', 'head');
				$this->_render = true;
			}
		}
		return $doc;
	}

	/**
	 * add an xhtml variable to the current skin
	 * @param string $varName
	 * @param string $value 
	 */
	public function addSkinVariable($varName, $value)
	{
		$this->_view->add($varName, $value);
	}

    /**
     * @param string $url set the canonical url for this page
     */
    public function setCanonical($url)
    {
        $this->_canonical = $url;
    }

    /**
     * set the A/B test version
     * @param String $version  the A/B version
     */
    public function setAB($version)
    {
        $this->_ab = $version;
	}


	/**
	 * usually the header is sent as soon as possible, but sometimes we may need to
	 * send cookies, etc... in that case the module should tell us to "waitForLoad"
	 * @param boolean $wait
	 */
	public function waitForLoad($wait = true)
	{
		$this->_waitForLoad = $wait;
	}

	/**
	 * @return Skin
	 */
	public static function getInstance($defaultSkin = 'defaultSkin.xhtml')
	{
		if (self::$_instance == null)
			self::$_instance = new Skin($defaultSkin);
		return self::$_instance;
	}

	public function setSkin($skin)
	{
		$this->_view->setViewFile($skin);
	}

	public function setError()
	{
		$this->_error = true;
	}

	public function addScript($script, $jasonCallback = '')
	{
        // add version number for local files
        if (!stristr($script, "http://"))
        {
            if (stristr($script, '?'))
                $script .= '&v='.HTML_VER;
            else
                $script .= '?v='.HTML_VER;
            if (defined('STATICDOMAIN') && stristr($script, '/scripts'))
            {
                $script = 'http://' . STATICDOMAIN . $script;
            }
            else
            {
                $script = 'http://' . DOMAIN . $script;
            }
        }

		if ($jasonCallback)
		{
			$script .= '&ajax=3&call='.$jasonCallback;
			if (!stristr($this->_setupJS, $jasonCallback)) {
				$this->_setupJS .= "var {$jasonCallback} = []; ";
			}
		}
        if (!in_array($script, $this->_scriptList)) {
            $this->_scriptList[] = $script;
        }
	}
    public function removeScript($script)
    {
        unset($this->_scriptList[$script]);
    }
	public function addSetupJS($code)
	{
		$this->_setupJS .= $code;
	}
	public function addJsCode($code)
	{
		$this->_code .= $code;
	}
    /**
     * @param String $style an href link to a script to add to the <head>
     */
	public function addStyle($style)
	{
        // add version number for local files
        if (!stristr($style, "http://"))
            $style .= '?='.HTML_VER;
        if (array_search($style, $this->_style))
            Logger::getLogger('mvc')->error("adding the same style 2x $style");
        else
            $this->_style[] = $style;
	}

    /**
     * force the entire style array
     * @param array $style array('http://path.to/your.css', .. etc)
     */
    public function setStyles(array $styles)
    {
        $this->_style = $styles;
    }

    /**
     * force the entire script array
     * @param array $style array('http://path.to/your.js', .. etc)
     */
    public function setScripts(array $scripts)
    {
        $this->_scriptList = $scripts;
    }


    /**
     * set the <title> for the rendered page
     * @param string $title 
     */
	public function setTitle($title)
	{
		$this->_title = $title;
        if (!$this->_shareTitle)
            $this->_shareTitle = $title;
	}

    /**
     * set the <meta description> for the rendered page
     * @param string $description 
     */
	public function setDescription($description)
	{
		$this->_description = $description;
        if (!$this->_description)
            $this->_description = $description;
	}

    /**
     * @return string the meta description or ''
     */
    public function getTitle()
    {
        return $this->_title;
    }

    /**
     * @return string the meta description or ''
     */
    public function getDescription()
    {
        return $this->_description;
    }

	public function render($returnResult = false): string
	{
        $doc = $this->renderHead(true, $returnResult);

        $this->_view->add('style', $this->_style);
        $this->_view->add('header', $this->_header);
        $this->_view->add('menu', $this->_menu);
        $this->_view->add('script', $this->_scriptList);
        $this->_view->add('code', $this->_code);
        $this->_view->add('setupJS', $this->_setupJS);
        $this->_view->add('content', $this->content);
		// UNSAFE
        $this->_view->add('ver', HTML_VER); // UNSAFE
		$this->_view->add('GOOGLE_ANALYTICS', GOOGLE_ANALYTICS);
		//dbg($this->_view);

		// UNSAFE
		$this->_view->add('500error', $this->_error);
		$this->_view->add('mobile', ($GLOBALS['MOBILE']) ? '1' : '0'); // from Mobile page handler

		/*
        dbg($this->_view);
        $logos = array();
        foreach (scandir(BASE_DIR . '/public/logos/new') as $file) {
            if (strpos($file, 'png') or strpos($file, 'jpg')) {
                $logos[] = '/logos/new/' . $file;
            }    
        }

		$logo = $logos[rand(0, count($logos)-1)]; 

		$this->_view->add('logo', $logo);
		$this->_view->add('logo', '/public/images/uc-logo.png');
		*/
		

		if (!$returnResult) {
			$doc .=  $this->_view->render('body', 'body') . "</html>";
		} else {
			echo $this->_view->render('body', 'body') . "</html>";
		}
		return $doc;
	}

	public function unRender(): void
	{
		$this->_render = false;
	}
}
