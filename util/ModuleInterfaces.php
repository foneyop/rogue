<?hh // decl
if (interface_exists('Renderable'))
    return;

    /**
     * depricated
     */ 
interface Renderable
{
    /**
     * render should create a string of HTML.  This will be incorporated into
     * the view.
     * @return string the HTML
     */
    public function render();
}

interface GetSettings
{
    /**
     * settings are simple key/value pairs that can be passed to modules that
     * settings come from PageModule setting information.  Available options are
     * strOption1, strOption2, intOption1
     * @param array $settings the PageModule settings, a hash of setting key/value pairs
     */
    public function getSettings(array $settings);
}

interface PageSettings
{
	public function addSetting($name, $value);
}

interface Ajax
{
    /**
     * Process an ajax request, one module\'s ajax method may handle many request
     * methods, or a single method.  You may also add many modules to the page that
     * handle ajax requests.  They may handle different ajax methods, or the same.
     * @param Req $request the request paramaters, ajax will contain the method name
     * @param BoomResponse $response the object that will hold all ajax response paramaters
     * @return string the ajax respose, usually a JSON string
     */
    public function ajax(Req $request, BoomResponse $response);
}

interface PostForm
{
    /**
     * process a form request
     */
    public function postForm(Req $request);
}

interface LoadModule
{
    /**
     * load data that is needed for the module.  This may prepare data for
     * Render(), or may set data on the page also.
     * NOTE: do NOT use $_GET or $_POST, please use the Req object
     * @param Req $request abstracted user request data
     */
    public function load(Req $request);
}

interface ModuleHandler
{
    /**
     * all boom module handlers must implement this interface to recieve
     * a reference to the containing BoomModule
     * @param BoomModule $module the module that holds the handler
     */
    public function setModule(BoomModule $module);
}

interface DynamicStrings
{
    /**
     * get a list of dynamic strings that this module supports.  Each named string should have a description.
     * return array (array ('productName' => 'the name of the currently viewed product'));
     * @return array an array of dynamic string names with a description.  key is the name, value is the description
     */
    public function getDynamicInfo();

    /**
     * get the dynamic values.  returned as an array of key value pairs where the key is the dynamic name and
     * the value is the dynamic value.
     * @return array an array of the modules dynamic values
     */
    public function getDynamicValues();
}

/**
 * generic callback interface.  Simply calls an action method
 */
interface Action
{
    public function action();
}

/**
 * return an array suitable for json_encoding  
 */
interface Ceral
{
    /**
     * @return array an array suitable for json encoding
     */
    public function mill();
}

