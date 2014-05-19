RoguePHP

*NOTE* *NOTE*
Rogue is currently being ported to the hack language.  Content will be changing rapidly over the next month.
*NOTE* *NOTE*


1. INTRODUCTION
RogePHP is a collection of PHP classes that allow you to perform common web 
functions easily and quickly without all the hasle of setting up large web
frameworks.   Use as much or as little as you like with no need for "Installing"
modifying your include path or any other pain in the ass that some "other"
frameworks require you to do.

Did we mention it's fast as hell?

2. CONFIGURATION
All RogePHP configuration is done though constants, GLOBAL variables or method
calls.  Yeah Yeah, I know GLOBAL variables are "bad" but you can't override a
constant so get over it.

3. VIEWS

panel is grabbing the body, ditching the panel div.
no way to handle if content()

If you have used Apache Wicket, the the XhtmlView will be familiar to you.  If
you are more comfortable with JSP or Zend style views, we can do that too.

The XhtmlView allows you to build your markup as a stand alone xhtml file.  You
can build it in dreamwaver, vim, emacs, notepad whatever.  Once you have your
markup with sample data, simply apply your rogue namespace attributes on the
markup and let Rogue handle the mapping.  Rogue will compile the pure xhtml
markup into a php file that maps the data into minified xhtml output.

example xhtml view:
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:rogue="http://roguephp.sourceforge.net">
    <head>
        <title></title>
    </head>
    <body>
        <div id="friend_list" rogue:panel="">
            <span>Search Google Contacts:</span><br/>
            <input id="friend_search" name="friend_search" size="20" />
			<!-- render a list of at most 20 friends, default max is 999 -->
            <ol rogue:list="friendList" rogue:max="20" class="SmallText">
                <li><input type="checkbox" rogue:name="friendList.getEmail" /><span rogue:id="friendList.getDisplayName|10"></span></li>
            </ol>
        </div>
    </body>
</html>

REQUIREMENTS:
VIEW_DIR and VIEW_CACHE_DIR constants need to be defined.  VIEW_CACHE_DIR 
needs to be web writable and can exist anywhere on the FS.  You can also
include views from absolute paths outside of VIEW_DIR.


EXAMPLE:
// the requirements
define('VIEW_DIR', '/path/to/view');
define('VIEW_CACHE_DIR', '/path/to/cache');
require_once '/full/path/to/rogue/views/XhtmlView.php';


// create a new view
$view = new XhtmlView('test/test.xhtml');
// assign data to the view
$view->add('foo', 'bar');
// render will return a string of HTML
echo $view->render();


3.1 View Modifiers
default view modifiers are located in XhtmlParser at the top in the class XmlMods

url: urlencode the parameter
deamp: change "&amp;" to "and", remove ' and &apos;
number: limit the number of characters that will be displayed
upper: uppercase all text
lower: lowercase all text
trim: remove leading and trailing whitespace
pre: prepend some text
post: append some text
cap: upercase the first letter of the first word
allcap: upercase the first letter of all words
ago: run the function timeAgo() on a unixtime stamp to get display time as "x units ago"

add your own view modifiers by calling "XmlMods::addPlugin('name', 'code');".   To create a new plugin
nl_to_br:

XmlMods::addPLugin('nl_to_br', 'strreplace("\n", "<br />", $v)';

in all cases $v will be the input.  This code will be injected into the compiled view



<a rogue:href="companyList.name|url|deamp|pre:/company/" rogue:id="companyList.name">Big Al's Big Bait Shop</a>



4. Lists:

<ul rogue:list="menu">
<li><a rogue:href="menu.href" rogue:class="menu.class|pre menu" rogue:id="menu.name">
</ul>

input:
$view->add('menu', array(
array('href' => '#l1', 'class' => 'select', 'name' => 'level 1'),
array('href' => '#l2', 'class' => '', 'name' => 'level 2')
));

output:

<ul>
<li><a href="#l1" class="select menu">level 1</a></li>
<li><a href="#l2" class=" menu">level 2</a></li>
</ul>


5. Conditionals:

<div class="error" rogue:id="errorMsg" rogue:if="errorMsg"><img src='error' /></div>

in this example the containing div and error image are ONLY displayed if the input "errorMsg" is added
to the view variables.  Otherwise, an empty string is printed here.

