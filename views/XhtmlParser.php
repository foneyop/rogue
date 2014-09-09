<?hh // decl
require_once ROGUE_DIR . '/views/XhtmlModifiers.php';
define('XHTML_DEBUG', false);
define('XHTML_EOL', "");
define('XHTML_EOT', "");
$GLOBALS['xtdbgidt'] = '';
function xtdbg($msg, $indent = false) { if (XHTML_DEBUG) echo "{$GLOBALS['xtdbgidt']}$msg\n"; if($indent) $GLOBALS['xtdbgidt'] .= '  '; }
function xtdbgfin() { $GLOBALS['xtdbgidt'] = substr($GLOBALS['xtdbgidt'], -2); }

//function ws($msg) { return trim($msg); }
// replace recursive whitespace
//function ws($msg) { return str_replace("\n", " ", trim($msg)); }
//function ws($msg) { return trim($msg); }
function ws($msg) { return preg_replace('/\s+/', ' ', $msg); }


class XhtmlParserException extends Exception
{
	public function __construct($message, $code = 1, $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}



/**
 * TODO: DO NOT INCLUDE THE TRAILING ROUGE PANEL CLOSE 
 */
class XhtmlParser
{
    protected $_xml;
    protected $_resources;
    protected $_finalMarkup;
    protected $_iteratorMarkup;

    protected $_mode;
    protected $_source;

    protected $_maxListMap;
    protected $_listStack;
    protected $_templateStack;
    protected $_varStack;
    protected $_conditionList;
    // current pointer needs to be a stack...
    protected $_currentList;
    protected $_nxtPtr;
    protected $_incPtr;
    protected $_currentCloseStack;
	protected $_conditionals;
	protected $_details;
	protected $_voidElements = array("AREA", "BASE", "BR", "COL", "COMMAND", "EMBED", "HR", "IMG", "INPUT", "KEYGEN", "LINK", "META", "PARAM", "SOURCE", "TRACK", "WBR");

    const NONE = 0;
    const ALL = 1;

    public function __construct()
    {
        $this->_log = Logger::getLogger('xhtml');
        $this->_maxListMap = array();
        $this->_listStack = array();
        $this->_details = array();
        $this->_templateStack = array();
        $this->_currentCloseStack = array();
        $this->_conditionList = array();
        $this->_currentList = '';
		$this->_conditionals = array();
        $this->_nxtPtr = 1;
		$this->addRoot('root');
    }

    /**
     * lookup a language resource and repalce the text.  This method addes the code
     * to the finalMarkup that does the lookup.
     * @param string $resourceName name of the resource to get the text from at runtime
     * @param string $baseName the name of the property to do any replacements in
     */
    protected function handleLanguageResource($baseName, $resourceName)
    {
        // this code is broken!!!
        die("DOES NOT SUPPORT LANGUAGE LOOKUP");

        $p = explode('.', $resourceName);
        $msg = Text::get($p[1], $p[0]);
        if (stristr($msg, '${') === false)
        {
            // this will need to determine the substitution resource {$baseName}
            // should be able to figure out any context
            die("DOES NOT SUPPORT LANGUAGE LOOKUP");
        }

        $this->_varStack[$idx][] = $this->handleLanguageResource(null, $resource);

        // TODO: we might get a perf boost by enclosing the preg_ with an if(stripos) { }
        $code = "\$msg = Text::get('{$p[2]}', '{$p[1]}');\n";
        // test for variable replacements ${foo}
        $code .= "preg_match_all('/(.*?)\\$w!\\{(\\w+)\\}/', \$msg, \$matches);\n";
        $code .= "if (isset(\$matches[0][0])) {";
        $code .= "\$msg = '';";

        $code .= "for(\$i=0,\$m=count(\$matches[0]); \$i<\$m; \$i++)\n {";

        $code .= "\$msg .= \$matches[1][\$i];\n";
        $code .= "if (isset({$baseName}[\$matches[2][\$i]]))\n { \$msg .= {$baseName}[\$matches[2][\$i]]; \n}";
        $code .= "else { \$msg .= \$matches[2][\$i];\n }";
        $code .= "} }";
        $code .= "\$data[] = \$msg;\n";
        return $code;
    }

    /**
     * parse out an xhtml view into compiled php code
     * @param string $xhtmlFile full path or relative to VIEW_DIR constant
     */
    public function parse($xhtmlFile, $outFile, $rootTag = "BODY", $panel = null)
    {
        //die("process panel: $panel");

        // initialize a new compiled vieww
        if ($xhtmlFile[0] == '/')
            $this->_source = $xhtmlFile;
        else
            $this->_source = VIEW_DIR . '/' . $xhtmlFile;

        // parse the XML view into php array
        $parser = xml_parser_create();
        if(!xml_parse_into_struct($parser, file_get_contents($this->_source), $this->_xml))
        {
            echo "<pre>\n";
            debug_print_backtrace();
            echo "\n" . xml_error_string(xml_get_error_code($parser)) . "\n";
            die("\nunable to parse: " . $this->_source);
        }
        xml_parser_free($parser);

        // reset the parser state
        $this->_resources = array();
        $this->_iteratorMarkup = array();
        $this->_finalMarkup = "<?php\n";
        $this->_mode = XhtmlParser::ALL;

        $closeTag = '';
        $closeLevel = 0;

        $section = 0;
        $this->Sproc($this->_xml, $panel);
        // make sure the directory exists in the compiled directory first
        $p = explode('/', $xhtmlFile);
        array_pop($p);
        $path = join('/', $p);
        $tmp = umask(0007);
        if (!file_exists(VIEW_CACHE_DIR . '/' . $path))
            mkdir(VIEW_CACHE_DIR . '/' . $path, 0770, true);

        // write out the compiled view
        file_put_contents($outFile, $this->_finalMarkup);
        umask($tmp);
        return;
    }

    
    /**
     * @return string the final markup that we wrote to disk
     */
    public function getMarkup()
    {
        return $this->_finalMarkup;
    }


    /**
     * @param array $tag the tag to test
     * @return boolean true | false 
     */
    private function isOpenTag(array $tag)
    {
		return ($tag['type'] == 'complete' || $tag['type'] == 'open') ? true : false;
        if ($tag['type'] == 'complete' || $tag['type'] == 'open')
            return true;
        return false;
    }

	private function isCdata(array $tag)
    {
		return ($tag['type'] == 'cdata') ? true : false;
    }

	/**
	 * @param array $tag the tag to test
	 * @return boolean true | false 
	 */
    private function isCompleteTag(array $tag)
    {
		return ($tag['type'] == 'complete') ? true : false;
        if ($tag['type'] == 'complete')
            return true;
        return false;
    }

    /**
     * @param array $tag the tag to test
     * @return boolean true | false 
     */
    private function isCloseTag(array $tag)
    {
		return ($tag['type'] == 'close') ? true : false;
        if ($tag['type'] == 'close')
            return true;
        return false;
    }

	/**
	 * @param array $tag the element to test
	 * @return boolean true | false 
	 */
	private function isConditional(array $tag)
	{
		if (isset($tag['attributes'])) {
			foreach ($tag['attributes'] as $name => $value)
				if ($name == 'RO:IFNOT') {
					// die("IF NOT!");
				}
				if ($name == 'RO:IF' || $name == 'RO:IFNOT') {
					$this->_conditionals[$this->getLevel($tag)] = 1;
					return true;
				}
		}
		return false;
	}

	/**
	 * @param array $tag the element to test
	 * @return boolean true | false 
	 */
	private function isList(array $tag)
	{
		if (isset($tag['attributes'])) {
			foreach ($tag['attributes'] as $name => $value)
				if ($name == 'RO:LIST')
					return true;
		}
		return false;
	}


    /**
	 * @TODO: debug script and link tags short closing  
     * @param array $tag the tag to test
     * @return boolean true | false 
     */
    private function requireFullClose(array $tag)
    {
        if (isset($tag['content']) || isset($tag['fullclose']) || $tag['tag'] == 'IFRAME' || $tag['tag'] == 'DIV' || $tag['tag'] == 'SCRIPT' || $tag['tag'] == 'TEXTAREA' || (isset($tag['value']) && strlen(ws($tag['value']))))
            return true;
        return false;
    }

	/**
	 * @param array $tag the tag to test
	 * @return boolean true | false 
	 */
    private function isIgnoredTag(array $tag)
    {
        return ($tag['tag'] == 'RO:CONTAINER' || $tag['tag'] == 'RO:CON'
         || $tag['tag'] == 'RO:CONTAINER' || $tag['tag'] == 'RO:CON') ? true : false;
    }

	/**
	 * @param array $elm the element to get the level for
	 * @return string in the format TAGNAME:TAGLEVEL 
	 */
    private function getLevel($elm)
    {
        return $elm['tag'] . ':' . $elm['level'];
    }


    /**
     * @param array $elm the element (ONLY OPEN tags!)
     * @param string $attributeName the name of the attribute to return
     * @return string the value of the attribute or false if not open tag or no attribute found
     */
    private function getAttribute($elm, $attributeName)
    {
        if (!$this->isOpenTag($elm))
            return false;
        if (isset($elm['attributes'][$attributeName]))
            return $elm['attributes'][$attributeName];
        return false;
    }

    /** DATA **/
    protected $_SnxtPtr = 0;
    protected $_StemplateIndex = 0;
    protected $_Stemplate = array();
    protected $_StemplateEnd = array();
    protected $_SidxStack = array();
    protected $_SvarStack = array();
	/** @var array $_Smap map object.value to element name */
    protected $_Smap = array();
    protected $_Sout = array();

    protected $_ScurrentAttributes = array();
    protected $_ScurrentTag = array();
    protected $_Sroots = array();
    protected $_ScurrentContent = '';

	/**
	 * for testing
	 * @return array internal state of the parser 
	 */
    public function getState()
    {
        return array(
            'idxStack' => $this->_SidxStack,
            'templateIndex' => $this->_StemplateIndex,
            'template' => $this->_Stemplate,
            'templateEnd' => $this->_StemplateEnd,
            'idxStack' => $this->_SidxStack,
            'varStack' => $this->_SvarStack,
            'roots' => $this->_Sroots,
            'currentTag' => $this->getTag()
        );
    }

    /**
     * add a new TEMPLATE (lists, and conditionals)
     * @param array $elm XML element
     */
    protected function addTemplate(array $elm)
    {
        xtdbg("addTemplate({$elm['tag']}) level:" . $this->getLevel($elm), true);
        $this->_StemplateIndex = $this->_SnxtPtr++;
        $this->_SidxStack[] = $this->_StemplateIndex;
        $this->_StemplateEnd[$this->_StemplateIndex] = $this->getLevel($elm);
		xtdbgfin();
    }

    /**
     * holds reference output to use for the current stack variable.   - NOT - overwrite
     * @param string $key the variable key (with or without LIST: prefix)
     * @param string $output the name of the output (usually parent variable)
     */
    protected function addOutput($key, $output)
    {
        $parts = explode(':', $output);
        $output = end($parts);
        $parts = explode(':', $key);
        $key = end($parts);
        if (!isset($this->_Sout[$key]))
            $this->_Sout[$key] = $output;
    }

    /**
     * holds reference output to use for the current stack variable.    does - OVERWRITE -
     * @param string $key the variable key (with or without LIST: prefix)
     * @param string $output the name of the output (usually parent variable)
     */
    protected function forceOutput($key, $output)
    {
        $parts = explode(':', $output);
        $output = end($parts);
        $this->_Sout[$key] = $output;
    }

    /**
     * - REMOVES THE VARIABLE FROM THE MAP -
     * @param string $key the variable key (with or without LIST: prefix)
     * @return string the name of the output variable
     */
    protected function getOutput($key)
    {
        $parts = explode(':', $key);
        $key = end($parts);
        $result = $this->_Sout[$key];
        unset($this->_Sout[$key]);
        return $result;
    }

    /**
     * append markup to the current template
     * @param array $elm the XML element
     * @param string $markup the markup to add to the template
     */
    protected function appendTemplate(array $elm, $markup)
    {
		xtdbg("appendTemplate({$elm['tag']}, $markup)", true);


        // if we have a static domain, rewrite all relative URLS to static absolute ones.
        if (defined('STATICDOMAIN')) {
            $markup = preg_replace('/(src\s*=\s*[\'"])\//', '${1}http://'. STATICDOMAIN . '/', $markup);
            $markup = preg_replace('/(href\s*=\s*[\'"])\//', '${1}http://'. DOMAIN .'/', $markup);
        }


		// conditionals require a new template
		if ($this->isConditional($elm)) {
			$this->addTemplate($elm);
		}

		// if the tag is a closing tag and the opening tag was a conditional
		if (isset($this->_conditionals[$this->getLevel($elm)])) {
			if (!isset($this->_Stemplate[$this->_StemplateIndex]))
            	$this->_Stemplate[$this->_StemplateIndex] = '';
            $this->_Stemplate[$this->_StemplateIndex] .= $markup;
		}

		// is this a closing tag for a template?  then set the current template to the previous
        $endLvl = (count($this->_StemplateEnd) > 0) ? end($this->_StemplateEnd) : 0;
        $thisLvl = $this->getLevel($elm);
        if ($thisLvl == $endLvl && $this->isCloseTag($elm)) {
            xtdbg($elm['tag'] . " backup template to level: $endLvl");
            array_pop($this->_StemplateEnd);
            $idx = array_pop($this->_SidxStack);
            $this->_StemplateIndex = end($this->_SidxStack);
        }

		// if this is not a contidional tag or ignored then append the markup to the current template
		// dont ignore lists or conditionals....
		if (!isset($this->_conditionals[$this->getLevel($elm)])) {
			xtdbg("add contidional template - $markup");
			if (!isset($this->_Stemplate[$this->_StemplateIndex]))
            	$this->_Stemplate[$this->_StemplateIndex] = '';
            $this->_Stemplate[$this->_StemplateIndex] .= $markup;
		}

		// add new templates for lists
		if (!$this->isConditional($elm) && $this->isList($elm)) {
			xtdbg("add contidional list template");
			$this->addTemplate($elm);
		}

		// this is a hack.   on short close we wont ever get back here to backup to the previous level
		if ($this->isCompleteTag($elm) && ($this->isConditional($elm))) {
			xtdbg("add contidional tempalte COMPLETE CLOSE");
            array_pop($this->_StemplateEnd);
            $idx = array_pop($this->_SidxStack);
            $this->_StemplateIndex = end($this->_SidxStack);
		}
		xtdbgfin();
	}

	/**
	 * add an attribute to the current template output (usually a %s)
	 * @param string $name attribute name
	 * @param string $value the content (%s)
	 * @param string $variable the variable content to replace
	 */
    protected function addAttribute($name, $value, $variable = '')
    {
		//echo "<pre>name: $name value: $value, var: $variable </pre>\n";
        if (stristr($variable, '{'))
            $variable = $this->inflateVariable($variable);
		//if ($name == "selected")
		//	die($variable);

        //die("$name / $value / $variable");
		if ($name == 'chref') {
			$name = 'href';
			//$this->_SvarStack[$root][] = $variable;
        	$this->_ScurrentAttributes[$name] = 'http://'.DOMAIN.$value;
        }
		else if ($name == 'csrc') {
			$name = 'src';
			//$this->_SvarStack[$root][] = $variable;
			if (defined('STATICDOMAIN'))
				$this->_ScurrentAttributes[$name] = 'http://'.STATICDOMAIN.$value;
			else
				$this->_ScurrentAttributes[$name] = 'http://'.DOMAIN.$value;
		}
        else {
			//$this->_SvarStack[$root][] = $variable;
			$this->_ScurrentAttributes[$name] = $value;//XmlMods::parse($value);
		}

        if ($variable) {
			$root = $this->_Sroots[$this->_StemplateIndex];
			$this->_SvarStack[$root][] = $variable;
        }
    }

    // todo add nesting
    protected function getSimpleRef($name) {
        $parts = explode('.', $name);
        if (count($parts) == 1)
            return "\$this->root['$name']";
        $res = '';
        $ctr = 0;
        $base = (isset($this->_Sroots[$parts[0]]) || in_array($parts[0], $this->_Sroots)) ? "\$item_{$parts[0]}" : "\$this->root['{$parts[0]}']";

        if (stristr($parts[1], '('))
            return "{$base}->{$parts[1]}";
        else
            return "{$base}['{$parts[1]}']";
        /*
        $closeure = array();
        for ($i=0,$m=count($parts)-1;$i<$m;$i++) {
            $res .= "\$item_{$parts[$i]}";
            if ($i<$m) {
                if (stristr($parts[$i],'(')) { $res .= '->'; $closeure[] = false; }
                else { $res .= '['; $ctr++; $closeure[] = true; }
            }
        }
        for($i=0;$i<$m;$i++) { $res .= ($closeure[$i]) ? '': "'"; }
        return $res;
         */
    }


    protected function inflateVariable($variable)
    {
        $parts = preg_split('/(\{.*?\})/', $variable, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = array();
        $vars = '';
        for($i=0,$m=count($parts);$i<$m;$i++) {
            if (stristr($parts[$i], '{')) {
                $result[] = '%s';
                $name = substr($parts[$i], 1, strlen($parts[$i])-2);
                $vars .= $this->getSimpleRef($name) . ',';
             //echo $this->getVarName(0, $name) . "\n";
             //echo $this->getVarMarkup('cat', $name) . "\n";
            }
            else {
                $result[] = $parts[$i];
            }
        }
        if ($variable[$m] == '}')
            $result[] = '%s';

        $vars = trim($vars, ',');
        $text = join('', $result);
        $res = "sprintf('$text', $vars);";
        return $res;
    }


	/**
	 * erased the current content and start new content output
	 * @param string $content the content to add
	 * @param string $variable the new root variable
	 */
    protected function replaceContent($content, $variable)
    {
		xtdbg("replaceContent($content, $variable)", true);
		// if we replace full content, then require full close
		$this->_ScurrentTag['fullclose'] = true;
        $this->_ScurrentContent = '';
		xtdbgfin();
        return $this->addContent($content, $variable);
    }

	/**
	 * @param string $content the content to add
	 * @param string $variable the new root variable
	 */
    protected function addContent($content, $variable = '')
    {
		xtdbg("addContent('$content', $variable)", true);
        if ($this->_ScurrentTag['tag'] == 'SCRIPT')
            $this->_ScurrentContent .= ltrim($content);
        else
            $this->_ScurrentContent .= preg_replace('/\s+/', ' ', $content);
        if ($variable)
		{
			if (strstr($variable, 'LIST:') !== false)
				$this->addRoot($variable);
			if (strstr($variable, 'IF:') !== false)
				$this->addRoot($variable);
			if (strstr($variable, 'IFNOT:') !== false)
				$this->addRoot($variable);
			$root = end($this->_Sroots);
			$root = $this->_Sroots[$this->_StemplateIndex];

			if (!isset($this->_SvarStack[$root])) {
				$this->_SvarStack[$root] = array();
                xtdbg("create new root var: $root");
            }
            xtdbg("add variable [$variable] to root [$root]");
            array_push($this->_SvarStack[$root], $variable);
		}
		xtdbgfin();
    }

	/**
	 * set the currently working tag, clear attributes, set the tag and clear the current content
	 * @param array $elm the tag
	 */
    protected function setTag(array $elm)
    {
		xtdbg("setTag({$elm['tag']})", true);
        $this->_ScurrentTag= $elm;
        $this->_ScurrentAttributes = array();
        if (isset($elm['value']) && $this->_ScurrentTag['tag'] != 'SCRIPT') {
            $this->_ScurrentContent = preg_replace('/\s+/', " ", $elm['value']);
		} else if (isset($elm['value'])) {
            $this->_ScurrentContent = preg_replace('/[ \t]+/', ' ', $elm['value']);
		}
		xtdbg("content: " . $this->_ScurrentContent);
		xtdbgfin();
    }

	/**
	 * create the markup for the current code and the current content
	 * @return string the template markup content for the current tag
	 */
    protected function getTag()
    {
		xtdbg("getTag()", true);
        $markup = '';
        // open
        if ($this->isOpenTag($this->_ScurrentTag) && !$this->isIgnoredTag($this->_ScurrentTag))
        {
            $markup =  '<' . strtolower($this->_ScurrentTag['tag']);
            foreach ($this->_ScurrentAttributes as $attr => $val) {
				if ($attr == 'optional')
					$markup .= " $val ";
				else
					$markup .= " $attr=\"".addslashes($val)."\"";
			}
            if (!$this->isCompleteTag($this->_ScurrentTag) || $this->requireFullClose($this->_ScurrentTag))
                $markup .= '>';
        }
        // content
		xtdbg("add content: " . $this->_ScurrentContent);
        $markup .= $this->_ScurrentContent;

        // complete
        if (!$this->isIgnoredTag($this->_ScurrentTag)) {
            if ($this->isCompleteTag($this->_ScurrentTag) && !$this->requireFullClose($this->_ScurrentTag))
            {
				//dbg($this->_ScurrentTag["tag"]);
				//dbg($this->_voidElements);
                //xtdbg("SHORT CLOSE");
				if (!in_array($this->_ScurrentTag["tag"], $this->_voidElements)) {
					$markup .= " />" . XHTML_EOT;
				}
				else {
					$markup .= ">" . XHTML_EOT;
				}
            }
            // complete requires full close...
            else if ($this->isCompleteTag($this->_ScurrentTag))
            {
                //xtdbg("IS COMPLETE CLOSE");
                $markup .= "</" . strtolower($this->_ScurrentTag['tag']) . ">" . XHTML_EOT;
            }
            else if ($this->isCloseTag($this->_ScurrentTag))
            {
                //xtdbg("IS EXTRA CLOSE [" . $this->_ScurrentTag['type']);
                $markup = '</' . strtolower($this->_ScurrentTag['tag']) . '>';
            }
        }
        //xtdbg("GETTAG: " . $this->_ScurrentTag['tag'] .  " -- " . $this->_ScurrentTag['type'] . "= $markup");
        return $markup;
    }

	/**
	 * @param string $attrName the name of the rogue attribute (msg, list, etc)
	 * @param type $value the attribute value
	 * @param array $elm the tab
	 * @return type 
	 */
    protected function SprocessRogueAttr($attrName, $value, array $elm)
    {
        $attrName = substr($attrName, strpos($attrName, ':')+1);
        xtdbg("processRogueAttr($attrName, $value, {$elm['tag']})", true);

        // handle resource lookup
        if ($attrName == 'msg') {
            $p = explode('.', $value);
            $msg = Text::get($p[1], $p[0]);
            if (stristr($msg, '{')) {
                $msg = $this->inflateVariable($msg);
                $this->replaceContent('%s', $msg);
            } else {
                $this->replaceContent($msg, '');
            }
        }

        // handle lists
        else if ($attrName == 'list') {
			$this->_currentList = $value;
            $this->replaceContent('%s', "LIST:$value");
        }
        // handle lists limits
        else if ($attrName == 'max') {
			$this->_details["LISTMAX:{$this->_currentList}"] = $value;
		}
        // handle conditionals
        else if ($attrName == 'if') {
			$this->_Stemplate[$this->_StemplateIndex] .= '%s';
            $this->replaceContent('', "IF:$value");
        }
		else if ($attrName == 'ifnot') {
			$this->_Stemplate[$this->_StemplateIndex] .= '%s';
            $this->replaceContent('', "IFNOT:$value");
        }

        // handle variable content substitutions
        else if ($attrName == 'id') {
            $this->replaceContent('%s', $value);
        }
        // handle variable id attributes substitutions
        else if ($attrName == 'data-id') {
            $this->addAttribute('id', '%s', $value);
        }
        // handle normal attribute substitutions
        else if ($attrName != 'panel') {
			// do not show empty selected attributes (common case)
			/*
			if (strstr($attrName, "selected")) {
				die("ATTR: $attrName : $value");
			}
			if (($attrName == "selected" && $value == "") || $value == "IGNORE") {
				die("$attrName : $value");
			}
			*/
			if ($attrName == "selected") {
				//$this->addAttribute($attrName, '%s', $value);
				$this->addAttribute("selected", '%s', $value);
			}
			else {
				$this->addAttribute($attrName, '%s', $value);
			}
        }
		xtdbgfin();
    }


    /**
     * handle a single xml node
     * @param array $elm the element
     */
    protected function ShandleElm(array $elm)
    {
        xtdbg("handlElm({$elm['tag']})", true);
		// flush out stale content
		$this->_ScurrentContent = '';
        $this->setTag($elm);

        // if we are only rendereing a panel and we hit that panel, then start rendering
        if ($this->isOpenTag($elm))// && $this->getAttribute($elm, 'RO:PANEL') == $panel)
        {
            if (isset($elm['attributes']))
            {
                xtdbg($elm['tag'] . " has attributes");
                // handle rogue attributes (not rogue-id)
                foreach ($elm['attributes'] as $attribute => $value)
                {
                    $attribute = strtolower($attribute);
                    if ($attribute == 'rogue:id' || $attribute == 'ro:id')
                        continue;
                    if (stristr($attribute, 'rogue:') !== false || stristr($attribute, 'ro:') !== false)
                        $this->SprocessRogueAttr($attribute, $value, $elm);
                    else
                        $this->addAttribute($attribute, $value);
                }
                // handle rogue-id attribute
                foreach ($elm['attributes'] as $attribute => $value)
                {
                    $attribute = strtolower($attribute);
                    if ($attribute == 'rogue:id' || $attribute == 'ro:id')
                        $this->SprocessRogueAttr($attribute, $value, $elm);
                }
            }
        }

        $this->appendTemplate($elm, $this->getTag());
		xtdbgfin();
    }

    /**
     * process a lsit of XML nodes
     * @param array $xml
     * @param string $panel the rogue panel to render
     * @return string
     */
    protected function Sproc(array $xml, $panel = null)
    {
        $this->addTemplate(array('tag' => 'root', 'level' => 0));

        $closeLevel = '';
        // the markup created from this tag
        foreach ($xml as $elm)
        {
            xtdbg("ELM: " . $elm['tag'] . ' - ' . $elm['type']);
            $level = $this->getLevel($elm);

            // handle the panel open
            if ($this->isOpenTag($elm) && ($this->getAttribute($elm, 'RO:PANEL') == $panel || $panel == null))
            {
                if ($this->isCompleteTag($elm)) {
                    throw new XhtmlParserException("ROGUE Panels must have at least 1 sub element (consider using RO:CONTAINER)  AT: " . print_r($elm, true));
				}
                $closeLevel = $level;
            }
            
            // continue rendering this section
            if ($closeLevel != '')
            {
                $this->ShandleElm($elm);
            }

            if ($this->isCloseTag($elm) && $level == $closeLevel)
            {
                xtdbg("close rogue panel: $level / $closeLevel");
                break;
            }
        }

		for ($i=0; $i<count($this->_Stemplate); $i++)
        {
			$name = $this->getFriendlyRoot($i);
            $this->_finalMarkup .= "\$template_{$name}=<<<EOT\n".$this->_Stemplate[$i]."\nEOT;\n";
        }
		$this->_finalMarkup .= "\$data_root = array();\n";
		$this->_finalMarkup .= "\$templateOut_root = '';\n";
		$this->SrecurseBuild('root');
        $this->_finalMarkup .= "\$result = vsprintf(\$template_root, \$data_root);\n";
        //$this->_finalMarkup .= "\$result = print_r(\$data_root, true);\n";
	}


    protected function SrecurseBuild($root, $doOut = true)
	{
		xtdbg("recurseBuild($root, $doOut)");
        if (strstr($root, 'LIST:') !== false)
            $name = substr($root, 5);
        else if (strstr($root, 'IF:') !== false)
            $name = substr($root, 3);
        else if (strstr($root, 'IFNOT:') !== false)
            $name = substr($root, 6);
        else
            $name = $root;

		// what we need to do loop over the vars and add them to the template
		//for ($i=1; $i<count($this->_SvarStack); $i++)
		//echo "<pre>root: $root\n";
		//print_r($this->_SvarStack);
		//echo "</pre>\n";


		if (isset($this->_SvarStack[$root])) {
		foreach ($this->_SvarStack[$root] as $index => $var)
		{
			if (strstr($var, 'LIST:') !== false)
			{
				$varName = substr($var, 5);
				//$this->getTemplateMarkup($varName);
				$this->_finalMarkup .= $this->getStartListMarkup($root, $var, $name);
				//$vn = $this->_SvarStack[$varName][0];
                $this->_finalMarkup .= "//recurse START $varName\n";
                $this->_Smap[$varName] = "\$item_{$varName}";
                // MIGHT NEED TO RETURN OUTPUT HERE.
                // ALSO NEED TO _NOT_ ALWAYS ADD FINAL MARKUP BEFORE RECURSE
                $this->SrecurseBuild($varName, true);
                $this->_finalMarkup .= "//recurse FIN $varName - $root - $var\n\n";
                $this->_finalMarkup .= $this->getFinListMarkup($root, $var);
                $this->_finalMarkup .= "// POST recurse FIN $varName\n\n";
			}
            else if (strstr($var, 'IF:') !== false || strstr($var, 'IFNOT:') !== false)
			{
				//$varName = substr($var, 3);
            	$varName = (strstr($var, 'IF:')) ? substr($var, 3) : substr($var, 6);
				// need to test if this is a COMPLETE and adjust correctly...
				$this->getTemplateMarkup($varName);
				//$out = $this->getIfMarkup($root, $var, $name);
				//$out .= $this->SrecurseBuild($varName, false);
				$negate = (strstr($var, 'IFNOT:') === false) ? false : true;
				$this->_finalMarkup .= $this->getStartIfMarkup($root, $var, $name, $negate) . " // START IF\n";
				// add the output var if we have it
				if(isset($this->_SvarStack[$varName]))
					$this->addOutput($var, $this->_SvarStack[$varName][0]);
				else
					$this->addOutput($var, "");

				$inner = $this->SrecurseBuild($varName, false);
				if ($inner)
					$this->_finalMarkup .= $inner;
				//else if ($this->_SvarStack[$root][$index+1] == $varName)
				//	$this->_finalMarkup .= $this->getVarMarkup($varName, $var);
				$this->_finalMarkup .= $this->getFinIfMarkup($root, $var, $name) . " // END IF $root, $var, $name\n";
			}
			else {
				$this->_finalMarkup .= $this->getVarMarkup($root, $var);
			}
				//$out = $this->getVarMarkup($root, $var);
				
			//$this->_finalMarkup .= $out;
			$this->_finalMarkup .= "####### ROW\n\n";
            unset ($this->_SvarStack[$root]);
		}
		}
        xtdbg("finish rogue recurse build");
    }

	protected function getTemplateMarkup($name)
	{
		$short = $this->makeName($name);
		$this->_finalMarkup .= "\$data_{$short} = array();\n";
		$this->_finalMarkup .= "\$templateOut_{$short} = '';\n";
	}

    protected function getStartIfMarkup($root, $name, $parentName, $negate)
	{
        $out = "// PARENT: $root, $name, $parentName\n";
		$varName = $this->getVarName($root, $name);
		// IFNOT
		if ($negate)
			$out .= "if(!isset($varName) || !$varName) {\n";
		else
			$out .= "if(isset($varName) && $varName) {\n";
        return $out;
    }

    protected function getFinIfMarkup($root, $name, $parentName)
    {
        $short = $this->makeName($name);
		$out = "// FIN PARENT: $root, $name, $parentName, $short\n";
		// handle the case where we have an inner template (like a list)
		if (isset($this->_Sout[$short]) && $this->_Sout[$short]) {
			$out .= "// inner template case\n";
			if (strstr($name, '.') == false) {
				$out .= (isset($varName) && $varName) ? "\$data_{$short}[] = \$templateOut_{$varName};//Sout\n" :
                    "\$data_{$short}[] = '';//Sout\n";
            }
			$out .= "\$data_{$root}[] = vsprintf(\$template_{$short}, \$data_{$short});\n";
			$out .= '}';
		}
		// note sure about this case
		/*
		else if ($varName) {
			die("CONDITIONAL CASE NOT HIT: $varName\n");
        	$out .= "\$data_{$parentName}[] = \$templateOut_{$short}; }\n";
		}
		*/
		// handle the case of an rogue:id and rogue:if on the same element
		else {
			$out .= "// rouge:if case\n";
			if (strstr($name, '.') == false)
				$out .= $this->getVarMarkup($short, $short, true);
			$out .= "\$data_{$root}[] = vsprintf(\$template_{$short}, \$data_{$short});\n";
			$out .= '}';
		}
        $out .= "else { \$data_{$parentName}[] = ''; }\n";
        return $out;
	}

	protected function getStartListMarkup($root, $name, $parentName)
	{
		$short = $this->makeName($name);
		$varName = $this->getVarName($root, $name);

		//$out = "\$data_{$parentName} = array(); // OUT LIST $parentName\n";
		$out = "\$templateOut_{$short} = '';\n";
		// avoid return value in write context here
		if (stristr($varName, '->')) {
			$varName2 = "\${$short}FN";
			$out .= "$varName2 = {$varName};\n";
			$varName = $varName2;
		}
				
$out .= "if(isset($varName)) {\n";
$out .= "\$list_{$short} = ($varName instanceOf iList) ? {$varName}->getArray() : $varName;\n";
$out .= "\$sz_{$short} = count(\$list_{$short}); \n";
if (isset($this->_details["LISTMAX:$short"])) { 
	$out .= "\$sz_{$short} = (\$sz_{$short} > {$this->_details["LISTMAX:$short"]}) ? {$this->_details["LISTMAX:$short"]} : \$sz_{$short};\n";
}
	
$out .= "for(\$i_{$short}=0; \$i_{$short}<\$sz_{$short}; \$i_{$short}++) {\n";
//$out .= "\$data_{$short} = array(); // IN LIST $parentName - $short\n";
$out .= "\$item_{$short} = \$list_{$short}[\$i_{$short}];\n";
$out .= "\$data_{$short} = array();\n";
return $out;
	}

	protected function getFinListMarkup($root, $name)
	{
		$short = $this->makeName($name);
$out = "\$templateOut_{$short} .= vsprintf(\$template_{$short}, \$data_{$short});\n";
$out .= "} }\n";
if (isset($this->_Sout[$short]))
	$root = $this->_Sout[$short];

$root = $this->makeName($root);
$out .= "\$data_{$root}[] = \$templateOut_{$short};\n";
return $out;
	}

	/**
	 * return the markup for a single variable replacement
	 * @param string $root the root element to get the var from (list, root node, etc)
	 * @param string $var the variable to get
	 * @param boolean $complete override detection of simple list values with complete get
	 * @return string the program code 
	 */
	protected function getVarMarkup($root, $var, $complete = false)
	{
        $out = '';
		xtdbg("getVarMarkup($root, $var, $complete)", true);
        if ($root != $var || $complete)
        {
				//die ("INDEX! [$root] [$var] [$comlete]"); }
            $varName = $this->getVarName($root, $var);
            $varName = str_replace('.', '_', $varName);
            $pos = strpos($varName, ']->');

			// if we are working on an element from the root, then make a temp object
			if (strstr($varName, 'root') && $pos)
            {
                $out .= "\$tempelm = " . substr($varName, 0, $pos+1) . ";\n";
				$varName = preg_replace('/\$this\-\>root\[\'\w+\'\]/', '\$tempelm', $varName);
				$varValue = XhtmlModifiers::parse($varName);
			}
			else {
				$varValue = XhtmlModifiers::parse($varName);
			}

			$modPos = explode('|', $varName);
			if (isset($modPos[1]))
				$varName = $modPos[0] . substr($varName, -2);

			/*
			if (($epos = strripos($varName, '\']')) > 1) {
				$varName .= substr($varName, $epos, strlen($varName));
			} */

            //$root = str_replace('.', '_', $root);
            $root = preg_replace('/[^a-zA-Z0-9]/', '_', $root);
			$bpos = strrpos($varName, ">");
			$varName = ($bpos > 7) ? substr($varName, 0, $bpos-1) : $varName;
			
			if($root != 'root' && $this->startsWith($var, "index")) {
				$parts = explode('|', $var);
				if (isset($parts[1])) {
					$parts[0] = "\$i_$root";
					$var = XhtmlModifiers::parse(implode('|', $parts), '%s');
				} else {
					$var = "\$i_$root";
				}
				xtdbgfin();
            	return "\$data_{$root}[] = $var;\n";
			}
			xtdbgfin();
			// 
			$parts = explode("|", $var);


			if ($root != 'root' && strstr($parts[0], "."))
			{
				// don't remap the this->root if we are just a method reference on an object
				if ((strstr($parts[0], ".get") === 0 || strstr($parts[0], '(')) && substr_count($parts[0], ".") == 1) {
				} else {
					$out = preg_replace('/this->root/', "item_{$root}", $out);
					$out .= "// $root / {$parts[0]} / $varName " .  substr_count($parts[0], ".") . "\n";
					$varName = preg_replace('/this->root/', "item_{$root}", $varName);
					$varValue = preg_replace('/this->root/', "item_{$root}", $varValue);
				}
			}


            if (stristr($var, '{')) {
                $var = $this->inflateVariable($var);
                return $out . "\$data_{$root}[] = $var// expanded \n";
            }
            if (stristr($varName, 'sprintf')) {
                return $out . "\$data_{$root}[] = $var\n"; // printf fixor??\n";
            }

			return $out . "\$data_{$root}[] = (isset($varName)) ? $varValue : ''; // fixor [$pos]??\n";
        }
		// handle the case where the list is just a value, not a key value
        else
        {
			xtdbgfin();
			$root = $this->makeName($root);
            return "\$data_{$root}[] = \$item_{$root}; // fix0r\n";
        }
	}

	// what we need to do loop over the vars and add them to the template
	protected function getVarName($i, $var)
	{
		// TODO: add index number here
		xtdbg("getVarName($i, $var)", true);
        if (strpos($var, 'LIST:') !== false)
		{
			$var = substr($var, 5);
			$this->addRoot($var);
		}
        else if (strpos($var, 'IF:') !== false)
		{
			$var = substr($var, 3);
			$this->addRoot($var);
		}
		else if (strpos($var, 'IFNOT:') !== false)
		{
			$var = substr($var, 6);
			$this->addRoot($var);
		}
		$parts = explode('.', $var);
		$last = strrpos($var, '.');
		$root = ($last) ? substr($var, 0, $last) : 'root';
        $method = end($parts);
		$base = (isset($parts[1]))? $parts[count($parts)-2] : $var;

		$pos = array_search($root, $this->_Sroots);
		if ($pos === false)
		{
			$root = 'root';
		}

		$res = ($root != 'root') ? $res = "\$data{$pos}['$var']" : "\$this->root['$base']";
		//echo "<pre>\n";
		//print_r($parts);
		//die("$var: $bar, $res");

        if (isset($this->_Smap[$root]))
        {
			$res = $this->_Smap[$root];
		}
		// replace 
                xtdbg("#### VAR NAME: LAST: $last VAR: $var ROOT: $root");

                $pos = array_search($root, $this->_Sroots);
                if ($pos === false)
                {
					xtdbg("%%%%% $i - $var - $last - $root");
					//die("no such root: [$root]");
					/*
					print_r($this->_Smap);
					die("MAP");
					return "\$this->root['$var']";
					 * 
					 */
                }
                //xtdbg("%%%%% __found id__ $i - $var - $last - $root");
                //throw new XhtmlParserException("unable to map xhtml var [$root] - " . print_r($this->_Sroots, true));

				$res = str_replace('.', '_', $res);

				if (isset($parts[1])) {
            		if (strstr($method, "get") === 0 || strstr($method, '(')) {
						if (strstr($method, '(') === false)
							$method .= '()';
						$res .=  "->$method";
					}
					else
                		$res .=  "['{$method}']";
				}


				xtdbgfin();
				return $res;






		$last = strrpos($var, '.');
		$root = ($last) ? substr($var, 0, $last) : 'root';
        $method = ($last) ? substr($var, $last) : $var;

		/*
		$parts = explode('.', $var);
		$root = 'root';//($last) ? substr($var, 0, $last) : 'root';
		//foreach ($parts as $part) {
		print_r($this->_Sroots);
		for ($i=0,$m=count($parts);$i<$m;$i++) {
			echo "TEST: {$parts[$i]}\n";
			if (array_search($parts[$i], $this->_Sroots) !== false)
				$root = $parts[$i];
		}
		die ($var);
        $method = end($parts);
		 */

        if (isset($this->_Smap[$root]))
        {
            if (strstr($method, "get") === 0 || strstr($method, '(')) {
                if (strstr($method, '(') === false)
                    $method .= '()';
                die("MAP TEST : ::: " .$this->_Smap[$root] . "->$method\n;");
                return $this->_Smap[$root] . "->$method";
                return XhtmlModifiers::parse($this->_Smap[$root] . "->$method");
            } else {
                return $this->_Smap[$root] . "['{$method}']";
            }
            //die("have root: $root : " . $this->_Smap[$root]);
        }
		xtdbg("#### VAR NAME: LAST: $last VAR: $var ROOT: $root");

		$pos = array_search($root, $this->_Sroots);
		if ($pos === false)
		{
			$root = 'root';
			xtdbg("%%%%% $i - $var - $last - $root");
			print_r($this);
            die("no such root: [$root]");
			return "'cant find $root';";
		}
		//xtdbg("%%%%% __found id__ $i - $var - $last - $root");
		//throw new XhtmlParserException("unable to map xhtml var [$root] - " . print_r($this->_Sroots, true));
		if ($root == 'root')
			return "\$this->root['$var']";
		return "\$data_{$pos}['$var']";
	}

	protected function addRoot($name)
	{
		xtdbg("addRoot($name)", true);
        if (strstr($name, 'LIST:'))
        {
            $this->_Sroots[] = substr($name, 5);
        }
        else if (strstr($name, 'IF:'))
        {
            $this->_Sroots[] = substr($name, 3);
        }
        else if (strstr($name, 'IFNOT:'))
        {
            $this->_Sroots[] = substr($name, 6);
        }
        else
        {
            $this->_Sroots[] = $name;
        }
		xtdbgfin();
	}

	protected function makeName($listName)
	{
		if (strstr($listName, 'LIST:') !== false)
			$listName = substr ($listName, 5);
		else if (strstr($listName, 'IF:') !== false)
			$listName = substr ($listName, 3);
		else if (strstr($listName, 'IFNOT:') !== false)
			$listName = substr ($listName, 6);
		$listName = preg_replace('/[^a-zA-Z0-9]/', '_', $listName);
		return $listName;
	}

	protected function getFriendlyRoot($i)
	{
		$name = $this->_Sroots[$i];
		$name = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
		return $name;
	}

	protected function startsWith($haystack, $needle)
	{
		return (substr($haystack, 0, strlen($needle)) === $needle);
	}

	protected function endsWith($haystack, $needle)
	{
		return (substr($haystack, strlen($needle) * -1) === $needle);
	}
}

