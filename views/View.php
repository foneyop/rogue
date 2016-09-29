<?hh
/**
 * This view looks a lot like a Zend_View.  You can assign variables to the view object, then include the your view.
 * The phtml will execute in the same scope as the view object, giving it access to all of the view member variables.
 *
 * Typically you would then display the view variables as: <?=$this->some_member_variable?>
 *
 * you can also incude php code directly into your view like:
 * <?php foreach ($this->some_array as $item) { echo $item; } ?>
 *
 * @see XhtmlView
 * @package views
 */
class View
{
    public $x = array();
	protected $view;

    public function __construct($_view)
	{
		assert(is_file($_view, "view file: [$_view] not found"));
	    $this->view = $_view;
	}
    public function __get($nm)
    {
        if (isset($this->x[$nm]))
            return $this->x[$nm];
        return "";
    }
    public function __set($nm, $val)
    {
        $this->x[$nm] = $val;
    }

    public function __isset($nm)
    {
        return isset($this->x[$nm]);
    }
    
    public function __unset($nm)
    {
        unset($this->x[$nm]);
    }


    public function setView($view)
    {
		assert(is_file($_view, "view file: [$view] not found"));
        $this->view = $view;
    }

    public function render()
	{
		require $this->view;
	}

	/**
     * renders the view
     */
	public function returnRender()
	{
		ob_start();
		require $this->view;
		$result = ob_get_contents();
		ob_end_clean();
		return $result;
	}
}
