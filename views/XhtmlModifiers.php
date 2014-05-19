<?hh // decl
/**
 * Modify Rouge Xhtml View parameters with any passed in modifier.
 * If the string has no modifiers, then the input string will be returned
 *
 * You should extend this class to provide additional functionality in your project
 *
 * @author Cory Marsh
 */
class XhtmlModifiers
{
    private static $_plugins = array();
	private static $_baseText = '';

    /**
     * recursively call Mod for each modifier (filter
     * @param String $code the content to modify, including modifiers "foobar|caps"
     * @return String the string with the modifier applied, eg: "ucfirst('foobar')";
     */
	public static function parse($code, $root = null)
	{
		$p = explode('|', $code);
		$v = '%s';

		if (isset($p[1])) {
			$suffix = '';
			if (($epos = stripos(end($p), '\'')) > 1) {
				$end = array_pop($p);
				$suffix = substr($end, $epos);
				$end = substr($end, 0, $epos);
				array_push($p, $end);
			}
			self::$_baseText = $p[0];
			for ($i=1; $i<count($p); $i++) {
				$v = XhtmlModifiers::mod($p[$i], $v);
			}
			$out = sprintf("$v", $p[0].$suffix);
			return $out;
		}
		else
			return sprintf("$v", $code);
	}

    /**
     * Run the input though the modifier filter
     * @param string $modType modifier filter $mapType
     * @param string $v
     * @return string $v with the modifier applied
     */
    protected static function mod($modType, $v)
    {
        $result = $v;
        if (is_numeric($modType))
		{
            return "substr($v, 0, $modType)";
		}

		$data = $modType;
		$parts = explode(":", $modType);

        switch($parts[0])
        {
            case "upper":
                $result = "strtoupper($v)";
                break;
            case "lower":
                $result = "strtolower($v)";
                break;
            case "trim":
                $result = "trim($v)";
                break;
            case "url":
                $result = "urlencode($v)";
                break;
            case "deamp":
                $result = "str_replace(array('&amp;', \"'\", '&#039;', '&apos;'), array('&', '', '', ''), $v)";
                break;
            case "cap":
            case "caps":
                $result = "ucfirst($v)";
                break;
            case "allcap":
            case "allcaps":
                $result = "ucwords($v)";
                break;
            case "post":
                $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8', false);
                $result = "$v . '{$parts[1]}'";
                break;
            case "escape":
                $result = "htmlspecialchars($v, ENT_QUOTES, 'UTF-8', false)";
                break;
            case "pre":
                $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8', false);
                $result = "'{$parts[1]}' . $v";
                break;
            case "strip":
                $result = "strip_tags($v)";
                break;
            case "ago":
                $result = "timeAgo($v)";
                break;
            case "plus":
                $result = "($v + {$parts[1]})";
                break;
            case "minus":
                $result = "($v - {$parts[1]})";
                break;
            case "strip":
                $result = "strip_tags($v)";
                break;
            case "int":
                $result = "intval($v)";
                break;
            case "plural":
                $result = "($v > 1) ? '$v {$parts[1]}s' : '$v {$parts[1]}'";
                break;
            default:
				if (preg_match('/last\(([\w]+,[\w]+)\)/', $parts[0], $matches)) {
					if (isset($matches[1])) {
						$result = "(\$i_{$matches[1]} == (\$sz_{$matches[1]} - 1)) ? '{$matches[2]}' : ''";
						//$result = "(".self::$_baseText." == $size -1) ? '{$matches[1]}' : ''";
						//$result = "BOOBOZ";
					}
				}
				else if (preg_match('/first\((\w+)\,(\w+)\)/', $parts[0], $matches)) {
					if (isset($matches[1])) {
						//die($v);
						$result = "(\$i_{$matches[1]} == 0) ? '{$matches[2]}' : ''";
						//$size = "\$sz_".substr(self::$_baseText, 2);
						//$result = "($size == 0) ? '{$matches[1]}' : ''";
						//$result = "BOOBOZ";
					}
				}
				else if (preg_match('/mod\(([\d]*),([\w\d]*)\)/', $parts[0], $matches)) {
					if (isset($matches[2])) {
						$result = "(".self::$_baseText." %% {$matches[1]} == 1) ? '{$matches[2]}' : ''";
					}
				}
                if (isset(self::$_plugins[$modType]))
                {
                    $result = vsprintf(self::$_plugins[$modType], $v);
                }
        }

        return $result;
    }

    /**
     * register a new modifier plugin
     * @param string $name the name of the modifier
     * @param string $code a function that returns the code to place into the rendered view.
     *   example: good values, strtolower($v), strreplace("\n", "<br />", $v);
     */
    public static function addPlugin($name, $code)
    {
        self::$_plugins[$name] = $code;
    }

}

