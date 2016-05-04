<?php

/**
 * The admin-panel specific functionality of the plugin.
 *
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
class LiteSpeed_Cache_Admin_Rules
{
	private static $instance;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.6
	 * @access   private
	 */
	private function __construct()
	{
	}

	/**
	 * Get the LiteSpeed_Cache_Admin_Rules object.
	 *
	 * @since 1.0.6
	 * @access public
	 * @return LiteSpeed_Cache_Admin_Rules Static instance of the LiteSpeed_Cache_Admin_Rules class.
	 */
	public static function get_instance()
	{
		if (!isset(self::$instance)) {
			self::$instance = new LiteSpeed_Cache_Admin_Rules();
		}
		return self::$instance;
	}

	/**
	 * Validate common rewrite rules configured by the admin.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param array $input The configurations selected.
	 * @param array $options The current configurations.
	 * @param array $errors Returns error messages added if failed.
	 * @return mixed Returns updated options array on success, false otherwise.
	 */
	public function validate_common_rewrites($input, $options, &$errors)
	{
		$content = '';
		$prefix = '<IfModule LiteSpeed>';
		$engine = 'RewriteEngine on';
		$suffix = '</IfModule>';
		$path = self::get_rules_file_path();

		if (($input[LiteSpeed_Cache_Config::OPID_MOBILEVIEW_ENABLED] === false)
			&& ($options[LiteSpeed_Cache_Config::OPID_MOBILEVIEW_ENABLED] === false)
			&& ($input[LiteSpeed_Cache_Config::ID_NOCACHE_COOKIES] === $options[LiteSpeed_Cache_Config::ID_NOCACHE_COOKIES])
			&& ($input[LiteSpeed_Cache_Config::ID_NOCACHE_USERAGENTS] === $options[LiteSpeed_Cache_Config::ID_NOCACHE_USERAGENTS])) {
			return $options;
		}

		clearstatcache();
		if (self::get_rules_file_contents($content) === false) {
			$errors[] = $content;
			return false;
		}
		elseif (!is_writable($path)) {
			$errors[] = __('File is not writable.', 'litespeed-cache');
			return false;
		}
		$off_begin = strpos($content, $prefix);
		//if not found
		if ($off_begin === false) {
			$output = $prefix . "\n" . $engine . "\n";
			$start_search = NULL;
		}
		else {
			$off_begin += strlen($prefix);
			$off_end = strpos($content, $suffix, $off_begin);
			if ($off_end === false) {
				$errors[] = sprintf(__('Could not find %s close.', 'litespeed-cache'),'IfModule');
				return false;
			}
			--$off_end; // go to end of previous line.
			$off_engine = stripos($content, $engine, $off_begin);
			if ($off_engine !== false) {
				$off_begin = $off_engine + strlen($engine) + 1;
				$output = substr($content, 0, $off_begin);
			}
			else {
				$output = substr($content, 0, $off_begin) . "\n" . $engine . "\n";
			}
			$start_search = substr($content, $off_begin, $off_end - $off_begin);
		}

		$id = LiteSpeed_Cache_Config::OPID_MOBILEVIEW_ENABLED;
		if ($input['lscwp_' . $id] === $id) {
			$options[$id] = true;
			$ret = self::set_common_rule($start_search, $output,
					'MOBILE VIEW', 'HTTP_USER_AGENT',
					$input[LiteSpeed_Cache_Config::ID_MOBILEVIEW_LIST],
					'E=Cache-Control:vary=ismobile', 'NC');

			if (is_array($ret)) {
				if ($ret[0]) {
					$start_search = $ret[1];
				}
				else {
					// failed.
					$errors[] = $ret[1];
				}
			}

		}
		elseif ($options[$id] === true) {
			$options[$id] = false;
			$ret = self::set_common_rule($start_search, $output,
					'MOBILE VIEW', '', '', '');
			if (is_array($ret)) {
				if ($ret[0]) {
					$start_search = $ret[1];
				}
				else {
					// failed.
					$errors[] = $ret[1];
				}
			}

		}

		$id = LiteSpeed_Cache_Config::ID_NOCACHE_COOKIES;
		if ($input[$id]) {
			$cookie_list = preg_replace("/[\r\n]+/", '|', $input[$id]);
		}
		else {
			$cookie_list = '';
		}

		$ret = self::set_common_rule($start_search, $output,
				'COOKIE', 'HTTP_COOKIE', $cookie_list, 'E=Cache-Control:no-cache');
		if (is_array($ret)) {
			if ($ret[0]) {
				$start_search = $ret[1];
			}
			else {
				// failed.
				$errors[] = $ret[1];
			}
		}


		$id = LiteSpeed_Cache_Config::ID_NOCACHE_USERAGENTS;
		$ret = self::set_common_rule($start_search, $output,
				'USER AGENT', 'HTTP_USER_AGENT', $input[$id], 'E=Cache-Control:no-cache');
		if (is_array($ret)) {
			if ($ret[0]) {
				$start_search = $ret[1];
			}
			else {
				// failed.
				$errors[] = $ret[1];
			}
		}


		if (!is_null($start_search)) {
			$output .= $start_search . substr($content, $off_end);
		}
		else {
			$output .= $suffix . "\n\n" . $content;
		}
		$ret = self::do_edit_rules($output, false);
		if ($ret === false) {
			$errors[] = sprintf(__('Failed to put contents into %s', 'litespeed-cache'), '.htaccess');
			return false;
		}
		return $options;
	}

	/**
	 * Gets the currently used rules file path.
	 *
	 * @since 1.0.4
	 * @access private
	 * @return string The rules file path.
	 */
	public static function get_rules_file_path()
	{
		return get_home_path() . '.htaccess';
	}

	/**
	 * Clear the rules file of any changes added by the plugin specifically.
	 *
	 * @since 1.0.4
	 * @access public
	 */
	public static function clear_rules()
	{
		$prefix = '<IfModule LiteSpeed>';
		$engine = 'RewriteEngine on';
		$suffix = '</IfModule>';
		$path = self::get_rules_file_path();

		clearstatcache();
		if (self::get_rules_file_contents($content) === false) {
			return;
		}
		elseif (!is_writable($path)) {
			return;
		}

		$off_begin = strpos($content, $prefix);
		//if not found
		if ($off_begin === false) {
			return;
		}
		$off_begin += strlen($prefix);
		$off_end = strpos($content, $suffix, $off_begin);
		if ($off_end === false) {
			return;
		}
		--$off_end; // go to end of previous line.
		$output = substr($content, 0, $off_begin);
		$off_engine = strpos($content, $engine, $off_begin);
		$output .= "\n" . $engine . "\n";
		if ($off_engine !== false) {
			$off_begin = $off_engine + strlen($engine);
		}
		$start_search = substr($content, $off_begin, $off_end - $off_begin);

		$ret = self::set_common_rule($start_search, $output,
				'MOBILE VIEW', '', '', '');

		if ((is_array($ret)) && ($ret[0])) {
			$start_search = $ret[1];
		}
		$ret = self::set_common_rule($start_search, $output,
				'COOKIE', '', '', '');

		if ((is_array($ret)) && ($ret[0])) {
			$start_search = $ret[1];
		}
		$ret = self::set_common_rule($start_search, $output,
				'USER AGENT', '', '', '');

		if ((is_array($ret)) && ($ret[0])) {
			$start_search = $ret[1];
		}

		if (!is_null($start_search)) {
			$output .= $start_search . substr($content, $off_end);
		}
		else {
			$output .= $suffix . "\n\n" . $content;
		}
		self::do_edit_rules($output);
		return;
	}

	/**
	 * Clean up the input string of any extra slashes/spaces.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $input The input string to clean.
	 * @return string The cleaned up input.
	 */
	private static function cleanup_input($input)
	{
		return stripslashes(trim($input));
	}

	/**
	 * Try to save the rules file changes.
	 *
	 * This function is used by both the edit .htaccess admin page and
	 * the common rewrite rule configuration options.
	 *
	 * This function will create a backup with _lscachebak appended to the file name
	 * prior to making any changese. If creating the backup fails, an error is returned.
	 *
	 * If $cleanup is true, this function strip extra slashes.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $content The new content to put into the rules file.
	 * @param boolean $cleanup True to strip extra slashes, false otherwise.
	 * @return mixed true on success, else error message on failure.
	 */
	private static function do_edit_rules($content, $cleanup = true)
	{
		$path = self::get_rules_file_path();

		clearstatcache();
		if (!is_writable($path) || !is_readable($path)) {
			unnset($path);
			return __('File not readable or not writable.', 'litespeed-cache'); // maybe return error string?
		}
		if (file_exists($path)) {
			//failed to backup, not good.
			if (!copy($path, $path . '_lscachebak')) {
				return __('Failed to back up file, abort changes.', 'litespeed-cache');
			}
		}

		if ($cleanup) {
			$content = self::cleanup_input($content);
		}

		// File put contents will truncate by default. Will create file if doesn't exist.
		$ret = file_put_contents($path, $content, LOCK_EX);
		unset($path);
		if (!$ret) {
			return __('Failed to overwrite ', 'litespeed-cache') . '.htaccess';
		}
		return true;
	}

	/**
	 * Parses the .htaccess buffer when the admin saves changes in the edit .htaccess page.
	 *
	 * @since 1.0.4
	 * @access public
	 */
	public function parse_edit_htaccess()
	{
		if ((is_multisite()) && (!is_network_admin())) {
			return;
		}
		if (empty($_POST) || empty($_POST['submit'])) {
			return;
		}
		if (($_POST['lscwp_htaccess_save'])
				&& ($_POST['lscwp_htaccess_save'] === 'save_htaccess')
				&& (check_admin_referer('lscwp_edit_htaccess', 'save'))
				&& ($_POST['lscwp_ht_editor'])) {
			$msg = self::do_edit_rules($_POST['lscwp_ht_editor']);
			if ($msg === true) {
				$msg = __('File Saved.', 'litespeed-cache');
				$color = LiteSpeed_Cache_Admin_Display::NOTICE_GREEN;
			}
			else {
				$color = LiteSpeed_Cache_Admin_Display::NOTICE_RED;
			}
			LiteSpeed_Cache_Admin_Display::get_instance()->add_notice($color, $msg);
		}

	}

	/**
	 * Gets the contents of the rules file.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $content Returns the content of the file or an error description.
	 * @return boolean True if succeeded, false otherwise.
	 */
	public static function get_rules_file_contents(&$content)
	{
		$path = self::get_rules_file_path();
		if (!file_exists($path)) {
			$content = __('.htaccess file does not exist.', 'litespeed-cache');
			return false;
		}
		else if (!is_readable($path)) {
			$content = __('.htaccess file is not readable.', 'litespeed-cache');
			return false;
		}

		$content = file_get_contents($path);
		if ($content == false) {
			$content = __('Failed to get .htaccess file contents.', 'litespeed-cache');
			return false;
		}
		// Remove ^M characters.
		$content = str_ireplace("\x0D", "", $content);
		return true;
	}

	/**
	 * Build the wrapper string for common rewrite rules.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $wrapper The common rule wrapper.
	 * @param string $end Returns the ending wrapper.
	 * @return string Returns the opening wrapper.
	 */
	private static function build_wrappers($wrapper, &$end)
	{
		$end = '###LSCACHE END ' . $wrapper . '###';
		return '###LSCACHE START ' . $wrapper . '###';
	}

	/**
	 * Updates the specified common rewrite rule based on original content.
	 *
	 * If the specified rule is not found, just return the rule.
	 * Else if it IS found, need to keep the content surrounding the rule.
	 *
	 * The return value is mixed.
	 * Returns true if the rule is not found in the content.
	 * Returns an array (false, error_msg) on error.
	 * Returns an array (true, new_content) if the rule is found.
	 *
	 * new_content is the original content minus the matched rule. This is
	 * to prevent losing any of the original content.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $content The original content in the .htaccess file.
	 * @param string $output Returns the added rule if success.
	 * @param string $wrapper The wrapper that surrounds the rule.
	 * @param string $cond The rewrite condition to use with the rule.
	 * @param string $match The rewrite rule to match against the condition.
	 * @param string $env The environment change to do if the rule matches.
	 * @param string $flag The flags to use with the rewrite condition.
	 * @return mixed Explained above.
	 */
	private static function set_common_rule($content, &$output, $wrapper, $cond,
			$match, $env, $flag = '')
	{

		$wrapper_end = '';
		$wrapper_begin = self::build_wrappers($wrapper, $wrapper_end);
		$rw_cond = 'RewriteCond %{' . $cond . '} ' . $match;
		if ($flag != '') {
			$rw_cond .= ' [' . $flag . ']';
		}
		$out = $wrapper_begin . "\n" . $rw_cond .  "\n"
			. 'RewriteRule .* - [' . $env . ']' . "\n" . $wrapper_end . "\n";

		// just create the whole buffer.
		if (is_null($content)) {
			if ($match != '') {
				$output .= $out;
			}
			return true;
		}
		$wrap_begin = strpos($content, $wrapper_begin);
		if ($wrap_begin === false) {
			if ($match != '') {
				$output .= $out;
			}
			return true;
		}
		$wrap_end = strpos($content, $wrapper_end, $wrap_begin + strlen($wrapper_begin));
		if ($wrap_end === false) {
			return array(false, __('Could not find wrapper end', 'litespeed-cache'));
		}
		elseif ($match != '') {
			$output .= $out;
		}
		$buf = substr($content, 0, $wrap_begin); // Remove everything between wrap_begin and wrap_end
		$buf .= substr($content, $wrap_end + strlen($wrapper_end));
		return array(true, trim($buf));
	}

	/**
	 * FInds a specified common rewrite rule from the .htaccess file.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $wrapper The wrapper to look for.
	 * @param string $cond The condition to look for.
	 * @param string $match Returns the rewrite rule on success, error message on failure.
	 * @return boolean True on success, false otherwise.
	 */
	public function get_common_rule($wrapper, $cond, &$match)
	{

		if (self::get_rules_file_contents($match) === false) {
			return false;
		}
		$suffix = '';
		$prefix = self::build_wrappers($wrapper, $suffix);
		$off_begin = strpos($match, $prefix);
		if ($off_begin === false) {
			$match = '';
			return true; // It does not exist yet, not an error.
		}
		$off_begin += strlen($prefix);
		$off_end = strpos($match, $suffix, $off_begin);
		if ($off_end === false) {
			$match = __('Could not find suffix ', 'litespeed-cache') . $suffix;
			return false;
		}
		elseif ($off_begin >= $off_end) {
			$match = __('Prefix was found after suffix.', 'litespeed-cache');
			return false;
		}

		$subject = substr($match, $off_begin, $off_end - $off_begin);
		$pattern = '/RewriteCond\s%{' . $cond . '}\s+([^[\n]*)\s+[[]*/';
		$matches = array();
		$num_matches = preg_match($pattern, $subject, $matches);
		if ($num_matches === false) {
			$match = __('Did not find a match.', 'litespeed-cache');
			return false;
		}
		$match = trim($matches[1]);
		return true;
	}

};
