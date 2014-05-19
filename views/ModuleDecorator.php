<?hh // decl

interface ModuleRenderable
{
    /**
     * render should create a string of HTML.  This will be incorporated into
     * the view.
     * @return string the HTML
     */
    public function renderDecorated(ModuleDecorator $decorator);
}

interface AutoHelp
{
    /**
     * @return string an html string of help message to display in a popup
     */
    public function getHelp();
}

interface Editable
{
    /**
     * @return string a url to link to for the edit
     */
    public function getEditLink();
}



/**
 * decorated a RogueModule with some HTML markup
 *
 * @author cory
 */
class ModuleDecorator
{
    protected $_view;
    protected $_content = '';

	public function decorate($module, $newrow = false)
    {
        $handler = null;
		$class = '';
        // handle depricated code...
        if ($module instanceOf BoomModule)
		{
            $handler = $module->getHandler();
			$set = $module->getSettings();
			if (isset($set['align']))
				$class = $set['align'];
		}

        // render an old module DEPRICATED
        if ($handler instanceOf Renderable)
            return $handler->render();

        // not renderable, return
        if (!$handler instanceof ModuleRenderable)
            return '<h1>not renderable</h1>';

        // renderable, figure out what to render...
		$type = $module->getType();

        // short cut for no module wrapper
        if ($type == 'no-wrapper')
        {
            //return '<div class="row">'.$handler->renderDecorated($this).'</div>';
            return $handler->renderDecorated($this);
        }
        $this->_view = new XhtmlView("modules-$type.xhtml");
        $this->_view->add('id', '');
        $this->_view->add('class', $class);
        $this->_view->add('content', $handler->renderDecorated($this), true);

        $panel = 'basic';
        // if the render method did not set the name, then we default it to the module name
        if (!$this->_view->get('title'))
            $this->_view->add('title', $module->getName());
        // always need a class
        if (!$this->_view->get('class'))
            $this->_view->add('class', '');

       
        // add edit links
        if ($handler instanceOf Editable)
        {
            $this->_view->add('edit', $handler->getEditLink());
            $panel = 'edit';
        }
        // add help links
        if ($handler instanceOf AutoHelp)
            $this->_view->add('help', $handler->getHelp());

        // return the rendered source
        $rendered = $this->_view->render('body', $panel);
        $this->_view = null;
        return $rendered . $this->_content;
    }

    public function decorateSimple($content, array $parameters)
    {
        $viewWrap = new XhtmlView("modules-basic.xhtml");
        $viewWrap->add('id', $parameters['id']);
        $viewWrap->add('class', $parameters['class']);
        $viewWrap->add('title', $parameters['title']);
        $viewWrap->add('content', $content);

        $foo = $viewWrap->render('body', 'basic');
        return $foo;
    }


    /**
     * add a parameter to the module render view. inputs for "module-wrapper" type modules
     * are: [id,class,title,content] optional: [edit,help]
     * inputs for "div-wrapper" are: [id,class,content]
     * @param String $name the name of the modules.xhtml panel input parameter
     * @param String $value the value of the parameter (be sure to use Text::get() for language support!)
     * @param boolean $append if the content should be appended or overwritten.  NOTICE: this behavior is opposite of XhtmlView->add()
     */
    public function addParameter($name, $value, $append = true)
    {
        if ($this->_view)
            $this->_view->add($name, $value, $append);
    }

    /**
     * extra HTML content to add to the end of the markup
     * @param type $content 
     */
    public function appendContent($content)
    {
        $this->_content .= $content;
    }

	public function clearContent()
	{
		$this->_content = '';
	}

}

