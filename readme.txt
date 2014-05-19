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

The XhtmlView allows you to build your markup as a stand alone xhtml file.  You
can build it in dreamwaver, vim, emacs, notepad whatever.  Once you have your
markup with sample data, simply apply your rogue namespace attributes on the
markup and let Rogue handle the mapping.  Rogue will compile the pure xhtml
markup into a php file that maps the data into minified xhtml output.

Rogue creates the fastest views possible in PHP.  It does this by avoiding the
expensive context switching between inline markup, and dynamic PHP code eg:
<h1><?=$content?></h1>
Switching between the markup content and the php code is expensive for the interpreter.
Rogue avoids this switch by building pure PHP programs and using heredocs and printf
to replace the content.  This typically results in views that are 30-50% faster than
Smarty Templates or Zend Views.


REQUIREMENTS:
VIEW_DIR and VIEW_CACHE_DIR constants need to be defined.  VIEW_CACHE_DIR 
needs to be web writable and can exist anywhere on the FS.  You can also
include views from absolute paths outside of VIEW_DIR.



EXAMPLE VIEW:

<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:ro="https://github.com/foneyop/rogue">
    <body ro:panel="default">
		<div>
			<h1 ro:id="foo">Content To Be Replaced</h1>
		</div>
    </body>
</html>


EXAMPLE CODE:
// the requirements
define('VIEW_DIR', '/path/to/view');
define('VIEW_CACHE_DIR', '/path/to/cache');
require_once '/full/path/to/rogue/views/XhtmlView.php';


// create a new view
$view = new XhtmlView('test.xhtml');
// assign data to the view
$view->add('foo', 'Content From Code');
// render will return a string of HTML
echo $view->render("body", "default");

OUTPUTS:
<div><h1>Content From Code</h1></div>


3.0 View Replacement

Views must be valid xhtml documents including the <?xml> header.  You can render
any tag from an xhtml view by calling render() on your view object with the name
of the tag and the rogue panel id.   For instance:
$view = new XhtmlView("relative/path/to/view.xhtml");
echo $view->render("body", "panel");

Content in your views can be replaced by using the ro:id attribute on your content.
Consider the following xhtml view file:

<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ro="https://github.com/foneyop/rogue">
    <body ro:panel="default">
		<div>
			<h1 ro:class="new_class" ro:id="foo">Content To Be Replaced</h1>
		</div>
    </body>
</html>

Adding the variable foo to the view, will replace the content "Content To Be Replaced" with the value
of foo.

<h1 ro:class="new_class" ro:id="foo">Content To Be Replaced</h1>
$view->add("foo", "New Content");
$view->add("new_class", "my css classes");
renders: <h1 class="my css classes">New Content</h1>

You can also replace xhtml attributes by placing ro: in front of the attribute name.  This will make
the attribute replaceable the same way that xhtml content can be replaced.


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

add your own view modifiers by calling "XmlMods::addPlugin('name', 'code');".   To create a new plugin.
nl_to_br:

Example of creating a plugin:
XmlMods::addPlugin('nl_to_br', 'strreplace("\n", "<br />", $v)';

This code "strreplace()" will be injected into the compiled view, in the compiled view the varialbe $v will contain the content to modify.

Example Using Modifiers:

<a ro:href="companyList.name|url|deamp|pre:/company/" ro:id="companyList.name">Big And Al's</a>
This will output $companyList["name"] or $companyList->getName() if $companyList is an object as the href
attribute for the a tag.  companyList.name will be url encoded, &amp; changed to "and" for SEO and the value
of company list will be prefixed with "/company/".  If name was "Big Mike &amp; Al&apos;s" it would be translated to:
"/company/Big+Mike+and+Als"



4. Lists:

<ul ro:list="menu">
	<li><a ro:href="menu.href" ro:class="menu.class|pre menu" ro:id="menu.name">
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

<div class="error" ro:id="errorMsg" ro:if="errorMsg"><img src='error' /></div>

in this example the containing div and error image are ONLY displayed if the input "errorMsg" is added
to the view variables.  Otherwise, an empty string is printed here.

