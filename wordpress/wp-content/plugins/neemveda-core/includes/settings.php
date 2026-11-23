<?php
/**
 * WooCommerce → neemveda settings. Values can also be set as constants in wp-config.php
 * (e.g. NV_SMS_PROVIDER, NV_MSG91_AUTHKEY, NV_ONESIGNAL_APP_ID, NV_SMTP_PASS); a constant wins over the option.
 */
defined('ABSPATH') || exit;

function nvc_fields() {
	return [
		'announce' => [
			'title'  => 'Announcement bar',
			'fields' => [
				'nv_announce_on'        => ['Show the announcement bar', 'checkbox', ''],
				'nv_announce_text'      => ['Text', 'text', 'Free delivery on orders of ₹499 and above · Cash on Delivery available'],
				'nv_announce_link'      => ['Link (optional)', 'url', ''],
				'nv_announce_link_text' => ['Link text', 'text', 'Shop now'],
			],
		],
		'accounts' => [
			'title'  => 'Accounts',
			'fields' => [
				'nv_welcome_on'     => ['Show the "Create your profile / Continue as guest" popup to new visitors', 'checkbox', '1'],
				'nv_guest_checkout' => ['Let customers order as guests (without a profile)', 'checkbox', '1'],
			],
		],
		'shop' => [
			'title'  => 'Shop',
			'fields' => [
				'nv_free_shipping_min' => ['Free delivery from (₹)', 'number', '499'],
				'nv_alert_email'       => ['Send new-order alerts to (email)', 'email', ''],
				'nv_map_center'        => ['Default map centre (lat,lng)', 'text', '11.0168,76.9558'],
			],
		],
		'otp' => [
			'title'  => 'OTP verification',
			'intro'  => 'Email OTP always works (it uses the site email). Phone OTP turns on automatically once an SMS provider is filled in here. MSG91 needs an Auth key and an approved OTP template ID; 2Factor needs an API key (and a DLT-approved template name).',
			'fields' => [
				'nv_sms_provider'      => ['SMS provider', 'select:none=None (phone OTP off),msg91=MSG91,2factor=2Factor', 'none'],
				'nv_msg91_authkey'     => ['MSG91 Auth key', 'secret', ''],
				'nv_msg91_template'    => ['MSG91 OTP template ID', 'text', ''],
				'nv_2factor_key'       => ['2Factor API key', 'secret', ''],
				'nv_2factor_template'  => ['2Factor template name (optional)', 'text', ''],
			],
		],
		'push' => [
			'title'  => 'Browser push notifications (OneSignal)',
			'intro'  => 'Create a free account at onesignal.com → New App → Web, set the site URL, then paste the App ID and REST API key here. Customers get a push for each order update; send offers from the OneSignal dashboard.',
			'fields' => [
				'nv_onesignal_app_id'  => ['OneSignal App ID', 'text', ''],
				'nv_onesignal_rest_key' => ['OneSignal REST API key', 'secret', ''],
			],
		],
	];
}

/** Read a setting: wp-config constant (upper-case name) first, then the saved option, then the default. */
function nvc_get($key) {
	$const = strtoupper($key);
	if (defined($const) && constant($const) !== '') {
		return constant($const);
	}
	foreach (nvc_fields() as $section) {
		if (isset($section['fields'][$key])) {
			return get_option($key, $section['fields'][$key][2]);
		}
	}
	return get_option($key, '');
}

add_action('admin_menu', function () {
	add_submenu_page('woocommerce', 'neemveda settings', 'neemveda settings', 'manage_woocommerce', 'nv-settings', 'nvc_settings_page');
}, 60);

// The settings page is open to shop managers, so let them save it too (options.php asks for manage_options by default).
add_filter('option_page_capability_nv_settings', fn() => 'manage_woocommerce');

add_action('admin_init', function () {
	foreach (nvc_fields() as $section) {
		foreach ($section['fields'] as $key => [$label, $type]) {
			register_setting('nv_settings', $key, [
				'sanitize_callback' => function ($v) use ($type, $key) {
					if ($type === 'checkbox') return $v ? '1' : '';
					if ($type === 'url') return esc_url_raw($v);
					if ($type === 'email') return sanitize_email($v);
					if ($type === 'number') return (string) max(0, (int) $v);
					if ($type === 'secret' && $v === '••••••••') return get_option($key, ''); // unchanged
					return sanitize_text_field($v);
				},
			]);
		}
	}
});

function nvc_settings_page() {
	if (!current_user_can('manage_woocommerce')) return;
	echo '<div class="wrap"><h1>neemveda settings</h1>';
	echo '<p>Status: Email ' . (nvc_mail_ready() ? '<strong style="color:#2F4F2F">ready</strong>' : '<strong style="color:#b0412e">not set up</strong> (SMTP)') .
		' · Phone OTP ' . (nvc_sms_ready() ? '<strong style="color:#2F4F2F">on</strong>' : '<strong>off</strong>') .
		' · Push ' . (nvc_push_ready() ? '<strong style="color:#2F4F2F">on</strong>' : '<strong>off</strong>') . '</p>';
	echo '<form method="post" action="options.php">';
	settings_fields('nv_settings');
	foreach (nvc_fields() as $section) {
		echo '<h2>' . esc_html($section['title']) . '</h2>';
		if (!empty($section['intro'])) echo '<p class="description" style="max-width:720px">' . esc_html($section['intro']) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ($section['fields'] as $key => [$label, $type, $default]) {
			$locked = defined(strtoupper($key)) && constant(strtoupper($key)) !== '';
			$val = get_option($key, $default);
			echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
			if ($locked) {
				echo '<em>Set in wp-config.php</em>';
			} elseif ($type === 'checkbox') {
				echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1"' . checked($val, '1', false) . '>';
			} elseif (str_starts_with($type, 'select:')) {
				echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
				foreach (explode(',', substr($type, 7)) as $opt) {
					[$v, $l] = explode('=', $opt, 2);
					echo '<option value="' . esc_attr($v) . '"' . selected($val, $v, false) . '>' . esc_html($l) . '</option>';
				}
				echo '</select>';
			} elseif ($type === 'secret') {
				echo '<input type="password" class="regular-text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . ($val ? '••••••••' : '') . '" autocomplete="new-password">';
			} else {
				$t = in_array($type, ['url', 'email', 'number'], true) ? $type : 'text';
				echo '<input type="' . $t . '" class="regular-text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}
	submit_button();
	echo '</form></div>';
}
