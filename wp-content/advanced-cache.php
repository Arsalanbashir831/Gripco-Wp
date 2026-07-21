<?php
# WP SUPER CACHE 1.2
# WP SUPER CACHE 0.8.9.1
# AL ADVANCED CACHE 1.0
$cache_fname = dirname( __FILE__ ) . "/plugins/airlift/buffer/cache.php";
$helper_fname = dirname( __FILE__ ) . '/plugins/airlift/helper.php';

if (file_exists($cache_fname) && file_exists($helper_fname)) {
	require_once $cache_fname;
	require_once $helper_fname;

	$GLOBALS['al_cache_skip_cookies'] = ["woocommerce_cart_hash", "woocommerce_items_in_cart", "wordpress_ic_session", "wp-wpml_current_language", "wp_woocommerce_session", "wp-settings-time", "wpcw_timezone"];
	$GLOBALS['al_cache_ignored_cookie_patterns'] = ["#^wp#i", "#^wordpress#i", "#^comment_author#i", "#woocommerce_cart_hash#i", "#woocommerce_items_in_cart#i"];
	$GLOBALS['al_cache_allowed_cookie_patterns'] = ["#wordpress_test_cookie#i"];
	$GLOBALS['al_cache_ignored_user_agents_patterns'] = [];
	$GLOBALS['al_cache_allowed_user_agents_patterns'] = [];
	$GLOBALS['al_cache_ignore_for_all_query_params'] = true;
	$GLOBALS['al_cache_ignored_query_params_patterns'] = [];
	$GLOBALS['al_cache_allowed_query_params_patterns'] = [];
	$GLOBALS['al_cache_dynamic_cookies'] = [];
	$GLOBALS['al_cache_mandatory_cookies'] = [];

	function hasIgnoredScript() {
		if (in_array(
			basename($_SERVER['SCRIPT_FILENAME']),
			array(
				'wp-app.php',
				'xmlrpc.php',
				'wp-cron.php',
			)
		)) {
			return true;
		}
		if (strstr($_SERVER['SCRIPT_FILENAME'], 'wp-includes/js')) {
			return true;
		}
		return false;
	}

	function hasIgnoredHeaders() {
		if (!empty($_SERVER['HTTP_X_WP_NONCE']) || !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) || !empty($_SERVER['HTTP_AUTHORIZATION'])) {
			return true;
		}
		return false;
	}

	function hasIgnoredCookies($ignored_cookies_for_optimization, $allowed_cookies_for_optimization) {
		if (!is_array($_COOKIE) || empty($_COOKIE)) {
			return false;
		}

		// Get dynamic cookies list
		$dynamic_cookies = array();
		if (array_key_exists('al_cache_dynamic_cookies', $GLOBALS) && is_array($GLOBALS['al_cache_dynamic_cookies'])) {
			$dynamic_cookies = $GLOBALS['al_cache_dynamic_cookies'];
		}

		// Get mandatory cookies list
		$mandatory_cookies = array();
		if (array_key_exists('al_cache_mandatory_cookies', $GLOBALS) && is_array($GLOBALS['al_cache_mandatory_cookies'])) {
			$mandatory_cookies = $GLOBALS['al_cache_mandatory_cookies'];
		}

		foreach (array_keys($_COOKIE) as $cookie_name) {
			// Skip dynamic cookies - they don't prevent optimization, just create variants
			if (in_array($cookie_name, $dynamic_cookies, true)) {
				continue;
			}

			// Skip mandatory cookies - they're handled separately
			if (in_array($cookie_name, $mandatory_cookies, true)) {
				continue;
			}

			$is_skipped = false;

			// First check if cookie should be skipped from ignore list
			foreach ($allowed_cookies_for_optimization as $skip_cookie) {
				if (ALHelper::safePregMatch($skip_cookie, $cookie_name)) {
					$is_skipped = true;
					break;
				}
			}

			// If cookie is not in skip list, check if it matches ignore patterns
			if (!$is_skipped) {
				foreach ($ignored_cookies_for_optimization as $ignored_cookie) {
					if (ALHelper::safePregMatch($ignored_cookie, $cookie_name)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	function hasIgnoredUserAgents($ignored_user_agents_for_optimization, $allowed_user_agents_for_optimization) {
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if (empty($user_agent)) {
			return false;
		}

		// First check if user agent should be allowed (skip prevention)
		if (is_array($allowed_user_agents_for_optimization)) {
			foreach ($allowed_user_agents_for_optimization as $allowed_ua) {
				if (ALHelper::safePregMatch($allowed_ua, $user_agent)) {
					return false;
				}
			}
		}
		
		// If user agent is not in allow list, check if it matches ignore patterns
		if (is_array($ignored_user_agents_for_optimization)) {
			foreach ($ignored_user_agents_for_optimization as $ignored_ua) {
				if (ALHelper::safePregMatch($ignored_ua, $user_agent)) {
					return true;
				}
			}
		}
		
		return false;
	}

	function isIgnoredQueryString($ignored_query_params_regexes_for_optimization, $allowed_query_params_regexes_for_optimization, $ignore_opt_for_all_query_params) {
		if (!$_GET) {
			return false;
		}
		$params = $_GET;
		if ($params) {
			ksort($params);
		}

		if(!$params) {
			return false;
		}

		// Strict mode: allow only specific query params, and skip if ignored ones exist
		if (!empty($ignore_opt_for_all_query_params)) {

			// Check each query param against patterns
			foreach (array_keys($params) as $query_param) {
				// FIRST: Check if query param matches ignore patterns (highest priority)
				if (!empty($ignored_query_params_regexes_for_optimization) && is_array($ignored_query_params_regexes_for_optimization)) {
					foreach ($ignored_query_params_regexes_for_optimization as $ignored_pattern) {
						if (ALHelper::safePregMatch($ignored_pattern, $query_param)) {
							return true; // Skip optimization - ignored takes absolute priority
						}
					}
				}
			}
			
			// SECOND: Check if query param matches allowed patterns
			foreach (array_keys($params) as $query_param) {
				if (!empty($allowed_query_params_regexes_for_optimization) && is_array($allowed_query_params_regexes_for_optimization)) {
					foreach ($allowed_query_params_regexes_for_optimization as $allowed_pattern) {
						if (ALHelper::safePregMatch($allowed_pattern, $query_param)) {
							return false;
						}
					}
				}
			}

			// No ignored and no allowed params found → skip optimization (strict mode)
			return true;
		}

		// Default behavior when strict mode is off
		// Check each query param against ignore patterns (ignored takes priority)
		foreach (array_keys($params) as $query_param) {
			if (!empty($ignored_query_params_regexes_for_optimization) && is_array($ignored_query_params_regexes_for_optimization)) {
				foreach ($ignored_query_params_regexes_for_optimization as $ignored_pattern) {
					if (ALHelper::safePregMatch($ignored_pattern, $query_param)) {
						return true; // Skip optimization
					}
				}
			}
		}

		return false; // Default allow optimization
	}

	function isIgnoredRequestMethod($allowed_methods) {
		if (in_array($_SERVER['REQUEST_METHOD'], $allowed_methods)) {
			return false;
		}
		return true;
	}

	/**
	 * Check if page can be optimized
	 * 
	 * Note: This determines if we should run optimizations (minification, lazy loading, etc.)
	 * Mandatory cookies are NOT checked here because:
	 * - Optimizations should ALWAYS run
	 * - Mandatory cookies only affect SERVING/CREATING cache files
	 * - cache.php's canServeCachedPage() handles mandatory cookie checking
	 * 
	 * @return bool True if page can be optimized
	 */
	function canOptimizePage() {
		if (hasIgnoredScript()) {
			return false;
		}
		if (hasIgnoredHeaders()) {
			return false;
		}

		$ignored_cookies_for_optimization = ["#wp-postpass_#i", "#wptouch_switch_toggle#i", "#comment_author_#i", "#comment_author_email_#i", "#wordpress_logged_in_.+#i"];
		$allowed_cookies_for_optimization = [];

		$ignored_user_agents_for_optimization = ["#Zapier#i"];
		$allowed_user_agents_for_optimization = [];
		
		$ignored_query_params_regexes_for_optimization = ["#^bvspeed#i", "#^noairlift#i", "#service_sign_v2#i"];
		$allowed_query_params_regexes_for_optimization = [];
		$ignore_opt_for_all_query_params = false;

		$allowed_methods = array("GET", "HEAD");
		
		if (hasIgnoredCookies($ignored_cookies_for_optimization, $allowed_cookies_for_optimization)) {
			return false;
		}
		if (hasIgnoredUserAgents($ignored_user_agents_for_optimization, $allowed_user_agents_for_optimization)) {
			return false;
		}
		if (isIgnoredRequestMethod($allowed_methods)) {
			return false;
		}
		if (isIgnoredQueryString($ignored_query_params_regexes_for_optimization, $allowed_query_params_regexes_for_optimization, $ignore_opt_for_all_query_params)) {
			return false;
		}

		return true;
	}

	if (canOptimizePage()) {
		$socache = new ALCache();
		$socache->startCaching();
	}
}