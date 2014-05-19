<?hh // decl

// define the path to the ROGUE install
define('ROGUE_DIR', '../..');
// path to the language resource files
define('I18N_DIR', getcwd() . '/resources');

// path to the view files
define('VIEW_DIR', getcwd(). '/');
// path to the cache directory
define('VIEW_CACHE_DIR', VIEW_DIR . 'compiled/');

// path to the log file
$GLOBALS['LOGFILE'] = '/tmp/rogue.log';
$GLOBALS['NoLoggerHandler'] = true;
 

// include the view class
require ROGUE_DIR . '/views/XhtmlView.php';
// i18n resources requires these additional rogue classes
require ROGUE_DIR . '/util/Logger.php';
require ROGUE_DIR . '/i18n/Text.php';
require ROGUE_DIR . '/cache/LocalCache.php';

// an example class to show how to pull data from objects in the xhtml
class example
{
    public function method()
    {
        return 'This data is from the example method call.  ';
    }
    public function getSomething()
    {
        return 'Getters are resolved as objects becuase they start with get';
    }

}

// define some array list data (these cold also be objects)
// todo, add support for list arrays...
$factoids = array(
array('name' => 'money', 'description' => 'is the root of all evil'),
array('name' => 'question', 'description' => 'to be or not to be, that is the question')
);

// new view
$view = new XhtmlView('example.xhtml');
// assign data
$view->add('title', 'the quick brown fox jumped over the lazy dog');
$view->add('link', 'http://github.com/FoneyOp/rogue');
$view->add('link_text', 'Rouge PHP should be on github');
$view->add('object', new example());
$view->add('factoids', $factoids);

// these replacements happen in the resource file
$view->add('name', 'REPLACED IN RESOURCE');
$view->add('label', 'A REPLACED THING');

// render it
$view->forceCompile();
echo $view->render();



