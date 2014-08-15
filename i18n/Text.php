<?php
/**
 * Description of GetText
 *
 * @author cory
 */
class Text
{
    private static $_lang = 'en';
    private static $_lang_page = '';
    private static $_cache = array();

    /**
     * @return string the 2 letter language code 
     */
    public static function getLanguage()
    {
        return self::$_lang;
    }

    public static function setLanguage($lang)
    {
        self::$_lang = $lang;
    }

    public static function setPage($page)
    {
        self::$_lang_page = $page;
    }


    /**
     * 
     * 
     * TODO: fix this code, then add code to map to the language file 
     * 
     * 
     * return the requested text string from the language data
     * @param string $text the name of the text string
     * @param string $page swich page context for this get only
     * @return string the actual text
     * @throws InvalidArgumentException if the language page was not cached
     *   and could not be loaded
     * @throws RuntimeException if the language page is not set
     */
    public static function con($model, $property, $default = "invalid data")
    {
        //return $default;

		// return the instance cache
		if (isset(self::$_cache[$model][$property]))
			return self::$_cache[$model][$property];
        //die("model: $model  prop: $property  def: $default");

		// the APC cache key
        $key = 'text:' . self::$_lang . ':' . $model . TEXT_VER;

		// fetch from APC
		$cache = LocalCache::getInstance();
		$data = $cache->get($key);

        // not in APC, fetch from disk
        if ($data == LocalCache::CACHE_MISS)
        {
			$data = array();
			$file = I18N_DIR . "/".self::$_lang."/model/".$model.'.properties';
            if (is_file($file))
            {
                $langLines = file($file);
                foreach ($langLines as $line)
                {
                    // format is key<TAB>text
                    $parts = explode("\t", $line);
                    if (isset($parts[1]))
                        $data[$parts[0]] = trim($parts[1]);
                    else
                    {
                        $parts = explode(":", $line);
                        if (isset($parts[1]))
                            $data[$parts[0]] = trim($parts[1]);
                    }
                }
                // cache for 1 day
            }
            $cache->set($data, 86400);
		}

		self::$_cache[$model] = $data;
		if (isset(self::$_cache[$model][$property]))
			return self::$_cache[$model][$property];
        return null;
    }



    /**
     * return the requested text string from the language data.   Support for
     * A/B testing.  If $_COOKIE['A'] is found, then {$text}-{$_COOKIE['A']}
     * will be used if one exists, otherwise, {$text} will be used.
     * 
     * @param string $text the name of the text string
     * @param string $page swich page context for this get only
     * @return string the actual text
     * @throws InvalidArgumentException if the language page was not cached
     *   and could not be loaded
     * @throws RuntimeException if the language page is not set
     */
    public static function get($text, $page = null)
    {
		if ($page == null)
			$page = self::$_lang_page;

        // set the A/B version
        $ver = (isset($_COOKIE['A']) && $_COOKIE['A'] != 'A') ? '-'.$_COOKIE['A'] : '';

		// return the instance cache, first look for A/B version
		if (isset(self::$_cache[$page][$text.$ver]))
			return self::$_cache[$page][$text.$ver];
		if (isset(self::$_cache[$page][$text]))
			return self::$_cache[$page][$text];

		// ensure proper setup
        if (!$page || !self::$_lang)
            throw new RuntimeException('no language or page set');

		// the APC cache key
        $key = 'text:' . self::$_lang . ':' . $page . TEXT_VER;

		// fetch from APC
		$cache = LocalCache::getInstance();
		$data = $cache->get($key);

		// not in APC, fetch from disk
        if ($data == LocalCache::CACHE_MISS)
        {
			$data = array();
			$file = I18N_DIR . "/".self::$_lang."/".$page.'.properties';
            #if (!is_file($file))
            if (!file_exists($file))
            {
                echo "<pre>DIE stat:";
                die("no such text file: [$file]");
            }
			$langLines = file($file);
			foreach ($langLines as $line)
			{
				// format is key<TAB>text
				$parts = explode("\t", $line);
				if (isset($parts[1]))
					$data[$parts[0]] = trim($parts[1]);
                else
                {
                    $pos = strpos($line, ':');
                    if ($pos > 0)
					{
						$name = substr($line, 0, $pos);
                        $data[$name] = substr($line, $pos+1);
					}
                }
			}
			// cache for 1 day
			$cache->set($data, 86400, $key);
		}

		self::$_cache[$page] = $data;
		if (isset(self::$_cache[$page][$text.$ver]))
			return self::$_cache[$page][$text.$ver];
		if (isset(self::$_cache[$page][$text]))
			return self::$_cache[$page][$text];

		echo "<pre>UNDEFINED: " . self::$_lang . " : " . $page . " : $text\n</pre>";
		die("foo");
    }

    // shit we have to inline this to have access to the replacement thing
function mapResource($resource)
{
    $p = explode('.', $resource);

    // get the language text
    if (isset(self::$_cache[$p[0]][$p[1]]))
        $msg = self::$_cache[$p[0]][$p[1]];
    else
        $msg = Text::get($p[1], $p[0]);

    // test for variable replacements ${foo}
    preg_match_all('/(.*?)\\$\\{(\\w+)\\}/', $msg, $matches);
    if (isset($matches[0][0]))
    {
        $msg = '';
        // for each replacement
        for($i=0,$m=count($matches[0]); $i<$m; $i++)
        {
            // add pre match
            $msg .= $matches[1][$i];
            // add the replacement
            if (isset($baseName[$matches[2][$i]])) { $msg .= $baseName[$matches[2][$i]]; }
            // add the 
            else { $msg .= $matches[2][$i];}
        }
    }
    return $msg;

}


}

/**
 * return the requested text string from the language data
 * @param string $text the name of the text string
 * @param string $page swich page context for this get only
 * @return string the actual text
 */
function gt($message, $page = null)
{
    return Text::get($message, $page);
}

?>
