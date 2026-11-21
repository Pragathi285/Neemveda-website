<?php
/**
 * Basic hardening:
 *   - don't reveal admin usernames (REST users list, ?author=N, author pages);
 *   - XML-RPC off (not used; a common brute-force route);
 *   - limit wrong logins: 8 failures from one address in 15 minutes locks logins from it for 15 minutes.
 */
defined('ABSPATH') || exit;

// REST API: hide the users list from visitors who aren't logged in.
add_filter('rest_endpoints', function ($endpoints) {
	if (is_user_logged_in()) return $endpoints;
	foreach (array_keys($endpoints) as $route) {
		if (preg_match('#^/wp/v2/users#', $route)) unset($endpoints[$route]);
	}
	return $endpoints;
});

// ?author=1 and /author/name/ pages would show the username: send visitors home instead.
add_action('template_redirect', function () {
	if ((isset($_GET['author']) || is_author()) && !current_user_can('list_users')) {
		wp_safe_redirect(home_url('/'), 301);
		exit;
	}
}, 1);
add_filter('author_link', fn() => home_url('/'));
add_filter('oembed_response_data', function ($data) {
	unset($data['author_name'], $data['author_url']);
	return $data;
});

// XML-RPC is not used by this shop.
add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', fn() => []);
add_filter('wp_headers', function ($headers) {
	unset($headers['X-Pingback']);
	return $headers;
});

// Limit failed logins (wp-login.php and the My account login form both go through this).
function nvc_login_key() {
	return 'nvc_login_fail_' . md5(function_exists('nvc_client_ip') ? nvc_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''));
}
add_action('wp_login_failed', function () {
	$k = nvc_login_key();
	set_transient($k, (int) get_transient($k) + 1, 15 * MINUTE_IN_SECONDS);
});
add_filter('authenticate', function ($user) {
	if ((int) get_transient(nvc_login_key()) >= 8) {
		return new WP_Error('nvc_locked', __('Too many failed login attempts. Please wait 15 minutes and try again, or reset your password.', 'neemveda'));
	}
	return $user;
}, 99);
add_action('wp_login', function () {
	delete_transient(nvc_login_key());
});

// Generic login error, so the form doesn't confirm which usernames exist.
add_filter('login_errors', function ($error) {
	return preg_match('/(incorrect|is not registered|Unknown username|Unknown email)/i', wp_strip_all_tags($error))
		? __('<strong>Error:</strong> Wrong username/email or password.', 'neemveda')
		: $error;
});
