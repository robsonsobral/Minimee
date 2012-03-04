<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

// our helper will require_once() everything else we need
require_once PATH_THIRD . 'minimee/models/Minimee_helper.php';

/**
 * ExpressionEngine - by EllisLab
 *
 * @package		ExpressionEngine
 * @author		ExpressionEngine Dev Team
 * @copyright	Copyright (c) 2003 - 2011, EllisLab, Inc.
 * @license		http://expressionengine.com/user_guide/license.html
 * @link		http://expressionengine.com
 * @since		Version 2.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * Minimee: minimize & combine your CSS and JS files. Minify your HTML. For EE2 only.
 * @author John D Wells <http://johndwells.com>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD license
 * @link	http://johndwells.com/software/minimee
 */
class Minimee_ext {

	/**
	 * EE, obviously
	 */
	private $EE;


	/**
	 * Standard Extension stuff
	 */
	public $name			= MINIMEE_NAME;
	public $version			= MINIMEE_VER;
	public $description		= MINIMEE_DESC;
	public $docs_url		= MINIMEE_DOCS;
	public $settings 		= array();
	public $settings_exist	= 'y';


	/**
	 * Our magical config class
	 */
	public $config;


	/**
	 * Reference to our cache
	 */
	public $cache;


	// ------------------------------------------------------


	/**
	 * Constructor
	 *
	 * NOTE: We never use the $settings variable passed to us,
	 * because we want our Minimee_config object to always be in charge.
	 *
	 * @param 	mixed	Settings array - only passed when activating a hook
	 * @return void
	 */
	public function __construct($settings = array())
	{
		// Got EE?
		$this->EE =& get_instance();

		// grab a reference to our cache
		$this->cache =& Minimee_helper::cache();

		// grab instance of our config object
		$this->config = Minimee_helper::config();
		
		Minimee_helper::log('Extension has been instantiated.', 3);
	}
	// ------------------------------------------------------


	/**
	 * Activate Extension
	 * 
	 * @return void
	 */
	public function activate_extension()
	{
		// reset our runtime to 'factory' defaults, and return as array
		$settings = $this->config->factory()->to_array();
	
		$data = array(
			'class'		=> __CLASS__,
			'hook'		=> 'template_post_parse',
			'method'	=> 'template_post_parse',
			'settings'	=> serialize($settings),
			'priority'	=> 10,
			'version'	=> $this->version,
			'enabled'	=> 'y'
		);
		
		$this->EE->db->insert('extensions', $data);

		Minimee_helper::log('Extension has been activated.', 3);
	}
	// ------------------------------------------------------


	/**
	 * Disable Extension
	 *
	 * @return void
	 */
	public function disable_extension()
	{
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->delete('extensions');

		Minimee_helper::log('Extension has been disabled.', 3);
	}
	// ------------------------------------------------------


	/**
	 * Method for template_post_parse hook
	 *
	 * @param 	string	Parsed template string
	 * @param 	bool	Whether is a sub-template or not
	 * @param 	string	Site ID
	 * @return 	string	Template string, possibly minified
	 */
	public function template_post_parse($template, $sub, $site_id)
	{
		// play nice with others
		if (isset($this->EE->extensions->last_call) && $this->EE->extensions->last_call)
		{
			$template = $this->EE->extensions->last_call;
		}

		// do nothing if not final template
		if($sub !== FALSE)
		{
			return $template;
		}
		
		// see if we need to post-render any plugin methods
		if (isset($this->cache['template_post_parse']))
		{
			if ( ! class_exists('Minimee'))
			{
				include_once PATH_THIRD . 'minimee/pi.minimee.php';
			}

			$m = new Minimee();
			
			// this tells Minimee that we are calling it from hook
			$m->calling_from_hook = TRUE;
			
			// save our TMPL values to put back into place once finished
			$tagparams = $this->EE->TMPL->tagparams;

			// loop through & call each method
			foreach($this->cache['template_post_parse'] as $needle => $tag)
			{
				Minimee_helper::log('Calling Minimee::' . $tag['method'] . '() during template_post_parse: ' . serialize($tag['tagparams']), 3);

				$this->EE->TMPL->tagparams = $tag['tagparams'];
				$out = $m->{$tag['method']}();
				$template = str_replace(LD.$needle.RD, $out, $template);
			}
			
			// put things back into place
			$this->EE->TMPL->tagparams = $tagparams;
		}
		
		// Are we configured to run through HTML minifier?
		if($this->config->is_no('minify') || $this->config->is_no('minify_html'))
		{
			Minimee_helper::log('HTML minification is disabled.', 3);
			return $template;
		}

		// is Minimee nonetheless disabled?
		if($this->config->is_yes('disable'))
		{
			Minimee_helper::log('HTML minification aborted because Minimee is disabled via config.', 3);
			return $template;
		}

		// we've made it this far, so...
		Minimee_helper::log('Running HTML minification.', 3);

		Minimee_helper::library('html');

		return Minify_HTML::minify($template);
	}
	// ------------------------------------------------------


	/**
	 * Save settings
	 *
	 * @return 	void
	 */
	public function save_settings()
	{
		if (empty($_POST))
		{
			Minimee_helper::log($this->EE->lang->line('unauthorized_access'), 1);
		}
		
		else
		{
			// grab our posted form
			$settings = $_POST;
			
			// checkboxes are funny: if they don't exist in post, they must be explicitly added and set to "no"
			$checkboxes = array(
				'combine_css',
				'combine_js',
				'minify_css',
				'minify_html',
				'minify_js'
			);
			
			foreach($checkboxes as $key)
			{
				if( ! isset($settings[$key]))
				{
					$settings[$key] = 'no';
				}
			}
	
			// run our $settings through sanitise_settings()
			$settings = $this->config->sanitise_settings(array_merge($this->config->get_allowed(), $settings));
			
			// update db
			$this->EE->db->where('class', __CLASS__)
						 ->update('extensions', array('settings' => serialize($settings)));
			
			Minimee_helper::log('Extension settings have been saved.', 3);

			// save the environment			
			unset($settings);

			// let frontend know we succeeeded
			$this->EE->session->set_flashdata(
				'message_success',
			 	$this->EE->lang->line('preferences_updated')
			);

			$this->EE->functions->redirect(BASE.AMP.'C=addons_extensions'.AMP.'M=extension_settings'.AMP.'file=minimee');
		}
	}
	// ------------------------------------------------------


	/**
	 * Settings Form
	 *
	 * @param	Array	Current settings from DB
	 * @return 	void
	 */
	public function settings_form($current)
	{
		$this->EE->load->helper('form');
		$this->EE->load->library('table');

		// Merge the contents of our db with the allowed
		$current = array_merge($this->config->get_allowed(), $current);

		// view vars		
		$vars = array(
			'config_loc' => $this->config->location,
			'disabled' => ($this->config->location != 'db'),
			'form_open' => form_open('C=addons_extensions'.AMP.'M=save_extension_settings'.AMP.'file=minimee'),
			'settings' => $current,
			'flashdata_success' => $this->EE->session->flashdata('message_success')
			);

		// return our view
		return $this->EE->load->view('settings_form', $vars, TRUE);			
	}
	// ------------------------------------------------------


	/**
	 * Update Extension
	 *
	 * @param 	string	String value of current version
	 * @return 	mixed	void on update / false if none
	 */
	public function update_extension($current = '')
	{
		/**
		 * Up-to-date
		 */
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
		
		/**
		 * 2.0.0
		 * 
		 * - refactor to use new Minimee_config object
		 */
		if ($current < '2.0.0')
		{
			$query = $this->EE->db
							->select('settings')
							->from('extensions')
							->where('class', __CLASS__)
							->limit(1)
							->get();
			
			if ($query->num_rows() > 0)
			{
				$settings = unserialize($query->row()->settings);

				// Sanitise & merge to get a complete up-to-date array of settings
				$settings = $this->config->sanitise_settings(array_merge($this->config->get_allowed(), $settings));
				
				// update db				
				$this->EE->db
						->where('class', __CLASS__)
						->update('extensions', array(
							'hook'		=> 'template_post_parse',
							'method'	=> 'template_post_parse',
							'settings' => serialize($settings)
						));
			}

			$query->free_result();			

			Minimee_helper::log('Upgraded to 2.0.0', 3);
		}

		// update table row with version
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->update(
					'extensions', 
					array('version' => $this->version)
		);

		Minimee_helper::log('Upgrade complete. Now running ' . $this->version, 3);
	}
	// ------------------------------------------------------

}
// END CLASS

	
/* End of file ext.minimee.php */ 
/* Location: ./system/expressionengine/third_party/minimee/ext.minimee.php */