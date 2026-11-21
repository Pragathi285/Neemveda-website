<?php
/**
 * Plugin Name: neemveda core
 * Description: neemveda shop features on top of WooCommerce: email + phone OTP verification at signup and checkout, delivery location pin on a map, order tracking (Packed / Shipped / Out for delivery / Delivered) with emails and a Track order page, admin new-order alerts, browser push notifications (OneSignal), announcement bar, contact form, newsletter and "Buy now". Settings: WooCommerce → neemveda settings.
 * Version: 1.0.0
 * Author: neemveda
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Text Domain: neemveda
 */

defined('ABSPATH') || exit;

const NVC_VERSION = '1.0.0';
define('NVC_DIR', plugin_dir_path(__FILE__));
define('NVC_URL', plugin_dir_url(__FILE__));

// Works with WooCommerce's order storage (HPOS) and the classic cart/checkout.
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
	}
});

require_once NVC_DIR . 'includes/settings.php';
require_once NVC_DIR . 'includes/mail.php';
require_once NVC_DIR . 'includes/sms.php';
require_once NVC_DIR . 'includes/otp.php';
require_once NVC_DIR . 'includes/location.php';
require_once NVC_DIR . 'includes/tracking.php';
require_once NVC_DIR . 'includes/alerts.php';
require_once NVC_DIR . 'includes/push.php';
require_once NVC_DIR . 'includes/announce.php';
require_once NVC_DIR . 'includes/shop.php';
if (defined('WP_CLI') && WP_CLI) {
	require_once NVC_DIR . 'includes/setup.php';
}

register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled('nvc_hourly')) {
		wp_schedule_event(time() + 300, 'hourly', 'nvc_hourly');
	}
});
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('nvc_hourly');
});
// Make sure the hourly job exists even if the plugin was activated by copying files.
add_action('init', function () {
	if (!wp_next_scheduled('nvc_hourly')) {
		wp_schedule_event(time() + 300, 'hourly', 'nvc_hourly');
	}
});
