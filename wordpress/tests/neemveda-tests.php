<?php
/**
 * neemveda end-to-end checks, run inside WordPress with WP-CLI:
 *
 *   docker compose run --rm -v "$PWD/tests:/tests:ro" cli wp eval-file /tests/neemveda-tests.php
 *
 * Emails are captured (not sent) and push is not called. Everything the tests create (a customer,
 * orders, transients) is deleted at the end, and changed settings are restored.
 * Prints one line per check and exits with code 1 if anything failed.
 */

if (!defined('ABSPATH')) {
	fwrite(STDERR, "Run with: wp eval-file neemveda-tests.php\n");
	exit(1);
}

/* ------------------------------------------------------------------ tiny test harness */
$GLOBALS['nvt'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'mail' => [], 'warnings' => [], 'cleanup' => []];

function nvt_ok($cond, $name, $detail = '') {
	if ($cond) {
		$GLOBALS['nvt']['pass']++;
		WP_CLI::log("  ✓ $name");
	} else {
		$GLOBALS['nvt']['fail']++;
		$GLOBALS['nvt']['fails'][] = $name . ($detail ? " ($detail)" : '');
		WP_CLI::log("  ✗ $name" . ($detail ? "  → $detail" : ''));
	}
}
function nvt_section($t) { WP_CLI::log("\n== $t"); }
function nvt_mail_reset() { $GLOBALS['nvt']['mail'] = []; }
function nvt_mails() { return $GLOBALS['nvt']['mail']; }
function nvt_mail_subjects() { return array_map(fn($m) => $m['subject'], nvt_mails()); }
function nvt_has_subject($needle) {
	foreach (nvt_mails() as $m) if (stripos($m['subject'], $needle) !== false) return $m;
	return null;
}

// Capture all email instead of sending it.
add_filter('pre_wp_mail', function ($null, $atts) {
	$GLOBALS['nvt']['mail'][] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message']];
	return true;
}, 1, 2);
// Never call OneSignal from tests.
add_filter('pre_http_request', function ($pre, $args, $url) {
	if (strpos($url, 'onesignal.com') !== false || strpos($url, 'msg91.com') !== false || strpos($url, '2factor.in') !== false) {
		return new WP_Error('nvt_blocked', 'blocked in tests');
	}
	return $pre;
}, 1, 3);
// Record PHP warnings/notices raised from neemveda code.
set_error_handler(function ($no, $str, $file, $line) {
	if (strpos($file, 'neemveda') !== false) {
		$GLOBALS['nvt']['warnings'][] = "$str in " . basename($file) . ":$line";
	}
	return false;
});

/** Call an AJAX handler and return its decoded JSON response. */
function nvt_ajax($handler, $post) {
	$_POST = $post;
	$_REQUEST = $post;
	add_filter('wp_doing_ajax', '__return_true');
	$die = function () { return function () { throw new Exception('nvt_ajax_done'); }; };
	add_filter('wp_die_ajax_handler', $die, 99);
	ob_start();
	try {
		call_user_func($handler);
	} catch (Exception $e) {
		// expected: wp_send_json ends with wp_die
	}
	$out = ob_get_clean();
	remove_filter('wp_die_ajax_handler', $die, 99);
	remove_filter('wp_doing_ajax', '__return_true');
	$_POST = $_REQUEST = [];
	return json_decode($out, true);
}

function nvt_new_order($args = []) {
	$product = wc_get_products(['limit' => 1, 'featured' => true])[0] ?? wc_get_products(['limit' => 1])[0];
	$order = wc_create_order(['customer_id' => $args['customer_id'] ?? 0]);
	$order->add_product($product, 1);
	$order->set_billing_first_name('Test');
	$order->set_billing_last_name('Customer');
	$order->set_billing_email($args['email'] ?? 'nvt-customer@example.com');
	$order->set_billing_phone($args['phone'] ?? '9000000099');
	$order->set_billing_address_1('1 Test Street');
	$order->set_billing_city('Coimbatore');
	$order->set_billing_state('TN');
	$order->set_billing_postcode('641001');
	$order->set_billing_country('IN');
	$order->set_payment_method('cod');
	$order->set_payment_method_title('Cash on Delivery');
	$order->calculate_totals();
	$order->update_meta_data('_nvt_test', '1');
	$order->save();
	$GLOBALS['nvt']['cleanup'][] = $order->get_id();
	return $order;
}

function nvt_clear_notices() { if (function_exists('wc_clear_notices')) wc_clear_notices(); }
function nvt_error_notices() { return function_exists('wc_get_notices') ? array_map(fn($n) => wp_strip_all_tags(is_array($n) ? $n['notice'] : $n), wc_get_notices('error')) : []; }

// WooCommerce session/cart so notices and the checkout hooks work from the command line.
if (function_exists('wc_load_cart')) wc_load_cart();
if (WC()->session && !WC()->session->has_session()) WC()->session->set_customer_session_cookie(true);

WP_CLI::log('neemveda tests on ' . home_url() . ' · WooCommerce ' . WC()->version . ' · WordPress ' . get_bloginfo('version'));

/* ================================================================== 1. setup & configuration */
nvt_section('Shop configuration');
nvt_ok(is_plugin_active('woocommerce/woocommerce.php'), 'WooCommerce is active');
nvt_ok(is_plugin_active('neemveda-core/neemveda-core.php'), 'neemveda-core is active');
nvt_ok(get_stylesheet() === 'neemveda', 'neemveda theme is active');
nvt_ok(get_option('woocommerce_currency') === 'INR', 'Currency is INR');
nvt_ok(get_option('woocommerce_enable_guest_checkout') === (nvc_get('nv_guest_checkout') ? 'yes' : 'no'), 'Guest checkout follows neemveda settings → Accounts');
$nvt_guest_before = get_option('nv_guest_checkout');
update_option('nv_guest_checkout', '');
nvt_ok(get_option('woocommerce_enable_guest_checkout') === 'no', 'Turning the setting off makes login needed to order');
update_option('nv_guest_checkout', '1');
nvt_ok(get_option('woocommerce_enable_guest_checkout') === 'yes', 'Turning it on lets guests order');
if ($nvt_guest_before === false) delete_option('nv_guest_checkout'); else update_option('nv_guest_checkout', $nvt_guest_before);
nvt_ok(function_exists('nvc_welcome_on_this_page'), 'Welcome popup (create profile / continue as guest) is loaded');
nvt_ok(get_option('woocommerce_enable_myaccount_registration') === 'yes', 'Sign up on My account is on');
$cod = get_option('woocommerce_cod_settings', []);
nvt_ok(($cod['enabled'] ?? '') === 'yes', 'Cash on Delivery is enabled');
$gateways = WC()->payment_gateways()->get_available_payment_gateways();
nvt_ok(isset($gateways['cod']) && count($gateways) === 1, 'COD is the only payment method', implode(',', array_keys($gateways)));

$flat = $free = null;
foreach (WC_Shipping_Zones::get_zones() as $z) {
	foreach ((new WC_Shipping_Zone($z['id']))->get_shipping_methods(true) as $m) {
		if ($m->id === 'flat_rate') $flat = $m;
		if ($m->id === 'free_shipping') $free = $m;
	}
}
nvt_ok($flat && (string) $flat->get_option('cost') === '50', 'Delivery charge is ₹50');
nvt_ok($free && (string) $free->get_option('min_amount') === (string) get_option('nv_free_shipping_min', 499), 'Free delivery minimum matches the setting', $free ? $free->get_option('min_amount') : 'missing');

nvt_ok((int) get_option('woocommerce_terms_page_id') === (int) (get_page_by_path('terms')->ID ?? -1), 'Checkout “terms” box links to the Terms & conditions page');
foreach (['about', 'contact', 'track-order', 'shipping-policy', 'refund-policy', 'privacy-policy', 'terms', 'credits', 'cart', 'checkout', 'my-account', 'shop', 'home'] as $slug) {
	$p = get_page_by_path($slug);
	nvt_ok($p && $p->post_status === 'publish', "Page /$slug/ is published");
}
nvt_ok(strpos(get_post(wc_get_page_id('checkout'))->post_content, '[woocommerce_checkout]') !== false, 'Checkout uses the classic shortcode (OTP + map hooks run there)');
nvt_ok(strpos(get_post(wc_get_page_id('cart'))->post_content, '[woocommerce_cart]') !== false, 'Cart uses the classic shortcode');
nvt_ok(strpos(get_page_by_path('track-order')->post_content, '[neemveda_track]') !== false, 'Track order page has the tracking form');
nvt_ok(strpos(get_page_by_path('contact')->post_content, '[neemveda_contact]') !== false, 'Contact page has the contact form');
nvt_ok((int) get_option('page_on_front') === get_page_by_path('home')->ID && get_option('show_on_front') === 'page', 'Home page is the front page');

$products = wc_get_products(['limit' => -1, 'status' => 'publish']);
nvt_ok(count($products) >= 3, 'At least 3 products are published', (string) count($products));
$featured = array_filter($products, fn($p) => $p->is_featured());
nvt_ok(count($featured) >= 1, 'A featured product exists (shown on the home page)');
foreach ($products as $p) {
	nvt_ok($p->get_price() !== '' && $p->is_purchasable() && $p->get_image_id(), "Product “{$p->get_name()}” has a price, can be bought and has an image");
}
nvt_ok(function_exists('nv_featured_product') && nv_featured_product(), 'Theme finds the featured product');

/* ================================================================== 2. email setup */
nvt_section('Email');
nvt_ok(nvc_mail_ready(), 'SMTP (Brevo) settings are present', 'NV_SMTP_HOST / NV_SMTP_PASS');
nvt_ok(apply_filters('wp_mail_from', 'x@x') === (defined('NV_MAIL_FROM') ? NV_MAIL_FROM : 'x@x'), 'Emails are sent from the shop address');
nvt_ok(apply_filters('wp_mail_from_name', 'WordPress') === 'neemveda', 'Sender name is neemveda');

/* ================================================================== 3. helpers */
nvt_section('Phone numbers and tokens');
nvt_ok(nvc_normalise_phone('+91 98765-43210') === '9876543210', 'Phone with +91 and spaces is accepted');
nvt_ok(nvc_normalise_phone('09876543210') === '9876543210', 'Phone with leading 0 is accepted');
nvt_ok(nvc_normalise_phone('919876543210') === '9876543210', 'Phone with 91 prefix is accepted');
nvt_ok(nvc_normalise_phone('5876543210') === '', 'Phone not starting 6–9 is rejected');
nvt_ok(nvc_normalise_phone('12345') === '', 'Short phone is rejected');
$tok = nvc_otp_token('email', 'Someone@Example.com');
nvt_ok(nvc_otp_token_valid($tok, 'email', 'someone@example.com'), 'Email token is valid (case-insensitive)');
nvt_ok(!nvc_otp_token_valid($tok, 'email', 'other@example.com'), 'Token for another email is rejected');
nvt_ok(!nvc_otp_token_valid($tok, 'phone', 'someone@example.com'), 'Token for another channel is rejected');
nvt_ok(!nvc_otp_token_valid($tok . 'x', 'email', 'someone@example.com'), 'Tampered token is rejected');
nvt_ok(!nvc_otp_token_valid('', 'email', 'someone@example.com'), 'Empty token is rejected');
$rk = 'nvt-rate-' . wp_generate_password(6, false);
$r = [nvc_rate_ok($rk, 2, 60), nvc_rate_ok($rk, 2, 60), nvc_rate_ok($rk, 2, 60)];
nvt_ok($r === [true, true, false], 'Rate limit blocks after the limit');
delete_transient('nvc_rl_' . md5($rk));

/* ================================================================== 4. OTP by email */
nvt_section('Email OTP (sign up)');
$email = 'nvt-' . wp_generate_password(6, false, false) . '@example.com';
$email = strtolower($email);
$nonce = wp_create_nonce('nvc_otp');
nvt_mail_reset();
$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'purpose' => 'register']);
nvt_ok(!empty($res['success']), 'Sending an email code succeeds', wp_json_encode($res));
$mail = nvt_mails()[0] ?? null;
preg_match('/(\d{6})/', $mail['subject'] ?? '', $m);
$code = $m[1] ?? '';
nvt_ok($mail && $mail['to'] === $email && $code, 'The code email goes to the customer with a 6-digit code', $mail['subject'] ?? 'no email');
nvt_ok($mail && strpos($mail['message'], $code) !== false, 'The code is also in the email body');
$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'purpose' => 'register']);
nvt_ok(empty($res['success']) && !empty($res['data']['wait']), 'Asking again within a minute is refused');
$res = nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'code' => $code === '111111' ? '222222' : '111111']);
nvt_ok(empty($res['success']), 'A wrong code is refused');
$res = nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'code' => '12']);
nvt_ok(empty($res['success']), 'A short code is refused');
$res = nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'code' => $code]);
nvt_ok(!empty($res['success']) && nvc_otp_token_valid($res['data']['token'] ?? '', 'email', $email), 'The right code returns a valid token');
$email_token = $res['data']['token'] ?? '';
$res = nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email, 'code' => $code]);
nvt_ok(empty($res['success']), 'A code cannot be used twice');

// Too many wrong tries lock the code.
$email2 = 'nvt-lock-' . wp_generate_password(5, false, false) . '@example.com';
$email2 = strtolower($email2);
nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email2, 'purpose' => 'register']);
for ($i = 0; $i < 5; $i++) nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email2, 'code' => '000000']);
$last = end($GLOBALS['nvt']['mail']);
preg_match('/(\d{6})/', $last['subject'] ?? '', $m2);
$res = nvt_ajax('nvc_ajax_otp_verify', ['nonce' => $nonce, 'channel' => 'email', 'value' => $email2, 'code' => $m2[1] ?? '']);
nvt_ok(empty($res['success']), 'After 5 wrong tries even the right code is refused');

$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'email', 'value' => 'not-an-email', 'purpose' => 'register']);
nvt_ok(empty($res['success']), 'An invalid email address is refused');
$admin_email = get_userdata(1) ? get_userdata(1)->user_email : get_option('admin_email');
$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'email', 'value' => $admin_email, 'purpose' => 'register']);
nvt_ok(empty($res['success']) && stripos($res['data']['message'] ?? '', 'already') !== false, 'Sign up with an email that already has an account is refused');
$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => 'bad', 'channel' => 'email', 'value' => $email, 'purpose' => 'register']);
nvt_ok(empty($res['success']), 'A request without a valid security token is refused');

nvt_section('Phone OTP');
if (nvc_sms_ready()) {
	nvt_ok(true, 'SMS provider configured: ' . nvc_get('nv_sms_provider'));
} else {
	$res = nvt_ajax('nvc_ajax_otp_send', ['nonce' => $nonce, 'channel' => 'phone', 'value' => '9000000099', 'purpose' => 'register']);
	nvt_ok(empty($res['success']), 'Phone OTP is off until an SMS provider is set (refused cleanly)');
}

/* ================================================================== 5. registration */
nvt_section('Sign up validation');
$run_reg = function ($post, $email) {
	$_POST = $post;
	$e = apply_filters('woocommerce_process_registration_errors', new WP_Error(), '', 'pw', $email);
	$_POST = [];
	return $e->get_error_codes();
};
$codes = $run_reg(['nv_name' => 'Asha', 'nv_phone' => '9000000099'], $email);
nvt_ok(in_array('nv_email_otp', $codes, true), 'Sign up without email verification is refused');
$codes = $run_reg(['nv_name' => '', 'nv_phone' => '9000000099', 'nv_email_token' => $email_token], $email);
nvt_ok(in_array('nv_name', $codes, true), 'Sign up without a name is refused');
$codes = $run_reg(['nv_name' => 'Asha', 'nv_phone' => '123', 'nv_email_token' => $email_token], $email);
nvt_ok(in_array('nv_phone', $codes, true), 'Sign up with a bad mobile number is refused');
$codes = $run_reg(['nv_name' => 'Asha', 'nv_phone' => '9000000099', 'nv_email_token' => nvc_otp_token('email', 'someoneelse@example.com')], $email);
nvt_ok(in_array('nv_email_otp', $codes, true), 'A token for a different email is refused');
$codes = $run_reg(['nv_name' => 'Asha Devi', 'nv_phone' => '9000000099', 'nv_email_token' => $email_token], $email);
nvt_ok($codes === [] || $codes === ['nv_phone_otp'], 'Valid sign up passes validation', implode(',', $codes));

$_POST = ['nv_name' => 'Asha Devi', 'nv_phone' => '+91 90000 00099', 'nv_email_token' => $email_token];
$user_id = wc_create_new_customer($email, '', wp_generate_password(16));
$_POST = [];
nvt_ok(!is_wp_error($user_id), 'Customer account is created', is_wp_error($user_id) ? $user_id->get_error_message() : '');
if (!is_wp_error($user_id)) {
	$GLOBALS['nvt']['user'] = $user_id;
	nvt_ok(get_user_meta($user_id, 'first_name', true) === 'Asha' && get_user_meta($user_id, 'last_name', true) === 'Devi', 'Name is saved on the account');
	nvt_ok(get_user_meta($user_id, 'billing_phone', true) === '9000000099', 'Mobile number is saved (normalised)');
	nvt_ok(get_user_meta($user_id, 'nv_email_verified', true) === '1', 'Email is marked verified');
}

/* ================================================================== 6. checkout */
nvt_section('Checkout checks');
$run_checkout = function ($post) {
	nvt_clear_notices();
	$_POST = $post;
	do_action('woocommerce_checkout_process');
	$_POST = [];
	return nvt_error_notices();
};
$errs = $run_checkout(['billing_phone' => '9000000099']);
nvt_ok((bool) array_filter($errs, fn($e) => stripos($e, 'pin your delivery location') !== false), 'Order without a map pin is refused', implode(' | ', $errs));
$errs = $run_checkout(['billing_phone' => '9000000099', 'nv_lat' => '0', 'nv_lng' => '0']);
nvt_ok((bool) array_filter($errs, fn($e) => stripos($e, 'pin') !== false), 'A 0,0 pin is refused');
$errs = $run_checkout(['billing_phone' => '9000000099', 'nv_lat' => '200', 'nv_lng' => '76.9']);
nvt_ok((bool) array_filter($errs, fn($e) => stripos($e, 'pin') !== false), 'An impossible pin is refused');
$errs = $run_checkout(['billing_phone' => '12', 'nv_lat' => '11.02', 'nv_lng' => '76.96']);
nvt_ok((bool) array_filter($errs, fn($e) => stripos($e, 'mobile') !== false), 'A bad mobile number at checkout is refused');
$errs = $run_checkout(['billing_phone' => '9000000099', 'nv_lat' => '11.023813', 'nv_lng' => '76.969126']);
nvt_ok($errs === [], 'Valid phone and pin pass the checks', implode(' | ', $errs));
$errs = $run_checkout(['billing_phone' => '9000000099', 'nv_no_pin' => '1']);
nvt_ok($errs === [], '“Use my address only” lets an order through without a pin', implode(' | ', $errs));
$errs = $run_checkout(['billing_phone' => '9000000099', 'nv_no_pin' => '1', 'nv_lat' => '200', 'nv_lng' => '76.9']);
nvt_ok((bool) array_filter($errs, fn($e) => stripos($e, 'pin') !== false), 'A broken pin is still refused even with “address only” ticked');
nvt_clear_notices();

$order = nvt_new_order(['customer_id' => $GLOBALS['nvt']['user'] ?? 0, 'email' => $email]);
$_POST = ['nv_lat' => '11.023813', 'nv_lng' => '76.969126'];
do_action('woocommerce_checkout_create_order', $order, []);
$_POST = [];
$order->save();
$order = wc_get_order($order->get_id());
nvt_ok((string) $order->get_meta('_nv_lat') === '11.023813' && (string) $order->get_meta('_nv_lng') === '76.969126', 'The pin is saved on the order');
nvt_ok(nvc_order_map_link($order) === 'https://www.google.com/maps?q=11.023813%2C76.969126', 'Google Maps link for the courier is correct', nvc_order_map_link($order));

/* ================================================================== 7. new order */
nvt_section('New order: emails and admin alert');
nvt_mail_reset();
do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
$order->update_status('processing');
$order = wc_get_order($order->get_id());
nvt_ok($order->get_meta('_nv_unseen') === '1', 'New order is flagged for the admin alert');
nvt_ok(in_array($order->get_id(), array_map(fn($o) => $o->get_id(), nvc_unseen_orders(50)), true), 'Admin alert list includes the new order');
$owner = nvt_has_subject('new order');
nvt_ok((bool) $owner, 'Shop owner gets the “new order” email', implode(' | ', nvt_mail_subjects()));
nvt_ok($owner && strpos($owner['message'], 'google.com/maps') !== false, 'Owner email has the map pin link');
$cust = null;
foreach (nvt_mails() as $m) if ($m['to'] === $email) $cust = $m;
nvt_ok((bool) $cust, 'Customer gets the order confirmation email');
nvt_ok($cust && stripos($cust['message'], 'track your order') !== false, 'Customer email has a “Track your order” link');
nvt_ok($cust && stripos($cust['message'], 'expected delivery') !== false, 'Customer email shows the expected delivery');

/* ================================================================== 8. tracking updates */
nvt_section('Tracking updates (admin order page)');
wp_set_current_user(1);
$admin_save = function ($order, $post, $new_status = null) {
	$_POST = array_merge(['nvc_tracking_nonce' => wp_create_nonce('nvc_tracking')], $post);
	if ($new_status) $_POST['order_status'] = 'wc-' . $new_status;
	nvc_save_tracking_box($order->get_id());
	if ($new_status) {
		$o = wc_get_order($order->get_id());
		$o->update_status($new_status); // what WooCommerce does after our box is saved
	}
	$_POST = [];
	delete_transient('nvc_notify_' . $order->get_id());
	return wc_get_order($order->get_id());
};
nvt_ok(function_exists('nvc_save_tracking_box'), 'Tracking box save handler exists');

$tomorrow = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
$in3 = wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS);

nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_expected' => $in3, 'nv_notify' => '1'], 'packed');
nvt_ok($order->get_status() === 'packed', 'Status changes to Packed');
nvt_ok((bool) nvt_has_subject('is packed'), '“Packed” email sent', implode(' | ', nvt_mail_subjects()));
nvt_ok(count(nvt_mails()) === 1, 'Only one email for a status change + new date', implode(' | ', nvt_mail_subjects()));
$packed = nvt_has_subject('is packed');
nvt_ok($packed && strpos($packed['message'], nvc_fmt_date($in3)) !== false, 'Packed email mentions the expected delivery date');

nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => $in3, 'nv_place' => 'Dispatched from Coimbatore', 'nv_notify' => '1'], 'shipped');
nvt_ok($order->get_status() === 'shipped', 'Status changes to Shipped');
$ship = nvt_has_subject('has shipped');
nvt_ok((bool) $ship, '“Shipped” email sent');
nvt_ok($ship && strpos($ship['message'], 'D998877') !== false, 'Shipped email has the tracking number');
nvt_ok($ship && stripos($ship['message'], 'Cash on Delivery') !== false, 'Shipped email reminds about the COD amount');
nvt_ok($order->get_meta('_nv_place') === 'Dispatched from Coimbatore', 'Location is saved');

nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => $in3, 'nv_place' => 'Reached Chennai hub', 'nv_notify' => '1']);
nvt_ok((bool) nvt_has_subject('Reached Chennai hub'), 'Location-only update sends its own email', implode(' | ', nvt_mail_subjects()));

nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => wp_date('Y-m-d', time() + 4 * DAY_IN_SECONDS), 'nv_notify' => '1']);
nvt_ok((bool) nvt_has_subject('Delivery date for order'), 'Changing only the date sends a “delivery date” email', implode(' | ', nvt_mail_subjects()));

nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS), 'nv_place' => 'Quiet update']);
nvt_ok(count(nvt_mails()) === 0, 'Unticking “Email the customer” sends nothing', implode(' | ', nvt_mail_subjects()));

nvt_section('Day-before reminder');
nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => $tomorrow, 'nv_notify' => '1']);
nvt_ok((bool) nvt_has_subject('Delivery date for order'), 'Setting tomorrow as the date sends the delivery date email');
nvt_ok($order->get_meta('_nv_reminder_sent') === $tomorrow, 'No separate reminder will follow (already told it is tomorrow)');
$order->delete_meta_data('_nv_reminder_sent');
$order->save();
nvt_mail_reset();
nvc_send_reminders();
if ((int) wp_date('G') >= 9) {
	nvt_ok((bool) nvt_has_subject('Arriving tomorrow'), 'Reminder email is sent for tomorrow’s delivery', implode(' | ', nvt_mail_subjects()));
	nvt_mail_reset();
	nvc_send_reminders();
	nvt_ok(!nvt_has_subject('Arriving tomorrow'), 'Reminder is not sent twice');
} else {
	nvt_ok(!nvt_has_subject('Arriving tomorrow'), 'Before 9 am no reminder goes out (it waits until 9 am)');
}

nvt_section('Out for delivery and delivered');
nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => $tomorrow, 'nv_notify' => '1'], 'out-for-delivery');
nvt_ok((bool) nvt_has_subject('Out for delivery today'), '“Out for delivery” email sent', implode(' | ', nvt_mail_subjects()));
nvt_mail_reset();
$order = $admin_save($order, ['nv_courier' => 'DTDC', 'nv_tracking_no' => 'D998877', 'nv_expected' => $tomorrow, 'nv_notify' => '1'], 'completed');
nvt_ok((bool) nvt_has_subject('Delivered'), '“Delivered” email sent', implode(' | ', nvt_mail_subjects()));
nvt_ok(count(array_filter(nvt_mail_subjects(), fn($s) => stripos($s, 'deliver') !== false)) === 1, 'Only one “delivered” email');
$log = $order->get_meta('_nv_track_log');
nvt_ok(is_array($log) && count($log) >= 6, 'Tracking history records every step', is_array($log) ? (string) count($log) : 'none');

/* ================================================================== 9. customer views */
nvt_section('Customer tracking views');
$html = nvc_render_tracking($order);
nvt_ok(strpos($html, 'Delivered') !== false && substr_count($html, 'is-done') === 6, 'Timeline shows all 6 steps done for a delivered order');
nvt_ok(strpos($html, 'Cash on Delivery: please keep') === false, 'No COD reminder after delivery');
$o2 = nvt_new_order(['email' => 'nvt-cancel@example.com']);
$o2->update_status('cancelled');
$html2 = nvc_render_tracking(wc_get_order($o2->get_id()));
nvt_ok(strpos($html2, 'Cancelled') !== false && strpos($html2, 'nv-track-steps') === false, 'Cancelled order shows “Cancelled” without steps');

$track = function ($q) {
	$_REQUEST = $q;
	$out = do_shortcode('[neemveda_track]');
	$_REQUEST = [];
	return $out;
};
wp_set_current_user(0);
$n = $order->get_order_number();
nvt_ok(strpos($track(['order' => $n, 'who' => $email]), 'nv-track-steps') !== false, 'Track page finds the order by order number + email');
nvt_ok(strpos($track(['order' => $n, 'who' => strtoupper($email)]), 'nv-track-steps') !== false, 'Email lookup ignores capital letters');
nvt_ok(strpos($track(['order' => $n, 'who' => '+91 90000 00099']), 'nv-track-steps') !== false, 'Track page finds the order by mobile number');
$wrong = $track(['order' => $n, 'who' => '9111111111']);
nvt_ok(strpos($wrong, 'nv-track-steps') === false && stripos($wrong, 'could not find') !== false, 'Wrong mobile number shows an error and no order details');
$bad = $track(['order' => 'abc', 'who' => $email]);
nvt_ok(stripos($bad, 'could not find') !== false, 'Non-numeric order number shows an error');
$refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 0.01, 'reason' => 'nvt']);
if (!is_wp_error($refund)) {
	$GLOBALS['nvt']['cleanup'][] = $refund->get_id();
	$r = $track(['order' => $refund->get_id(), 'who' => $email]);
	nvt_ok(stripos($r, 'could not find') !== false, 'Looking up a refund number does not break the page');
} else {
	nvt_ok(false, 'Could create a test refund', $refund->get_error_message());
}
nvt_ok(strpos($track([]), 'nv-track-form') !== false, 'Empty Track page shows the form');

/* ================================================================== 10. shop extras */
nvt_section('Shop extras');
$p = wc_get_products(['limit' => 1, 'featured' => true])[0];
$flash = apply_filters('woocommerce_sale_flash', '<span class="onsale">Sale!</span>', null, $p);
nvt_ok(preg_match('/Save \d+%/', $flash) === 1, 'Sale badge shows “Save X%”', wp_strip_all_tags($flash));
$flat_rate = new WC_Shipping_Rate('flat_rate:1', 'Delivery', 50, [], 'flat_rate', 1);
$free_rate = new WC_Shipping_Rate('free_shipping:2', 'Free delivery', 0, [], 'free_shipping', 2);
$rates = apply_filters('woocommerce_package_rates', ['flat_rate:1' => $flat_rate, 'free_shipping:2' => $free_rate], []);
nvt_ok(array_keys($rates) === ['free_shipping:2'], 'When free delivery applies, the ₹50 option is hidden');
$rates = apply_filters('woocommerce_package_rates', ['flat_rate:1' => $flat_rate], []);
nvt_ok(array_keys($rates) === ['flat_rate:1'], 'Below the minimum, ₹50 delivery is shown');
$_REQUEST['nv_buy_now'] = '1';
nvt_ok(apply_filters('woocommerce_add_to_cart_redirect', 'x') === wc_get_checkout_url(), '“Buy now” goes straight to checkout');
unset($_REQUEST['nv_buy_now']);
nvt_ok(apply_filters('woocommerce_add_to_cart_redirect', 'x') === 'x', '“Add to cart” stays on the page');

$old_min = get_option('nv_free_shipping_min', '499');
update_option('nv_free_shipping_min', '650');
$min_now = null;
foreach (WC_Shipping_Zones::get_zones() as $z) foreach ((new WC_Shipping_Zone($z['id']))->get_shipping_methods(true) as $m) if ($m->id === 'free_shipping') $min_now = get_option('woocommerce_free_shipping_' . $m->instance_id . '_settings')['min_amount'] ?? null;
nvt_ok($min_now === '650', 'Changing “Free delivery from” in settings updates WooCommerce', (string) $min_now);
update_option('nv_free_shipping_min', $old_min);

$old_on = get_option('nv_announce_on');
update_option('nv_announce_on', '1');
ob_start(); do_action('wp_body_open'); $bar = ob_get_clean();
nvt_ok(strpos($bar, 'nv-announce') !== false, 'Announcement bar shows when switched on');
update_option('nv_announce_on', '');
ob_start(); do_action('wp_body_open'); $bar = ob_get_clean();
nvt_ok(strpos($bar, 'nv-announce') === false, 'Announcement bar hidden when switched off');
update_option('nv_announce_on', $old_on);

nvt_ok(strpos(do_shortcode('[neemveda_contact]'), 'action" value="nv_contact"') !== false, 'Contact form renders');
nvt_ok(nvc_push_order($order, 'x', 'y') === false, 'Push does nothing until OneSignal is set up (no errors)');
nvt_ok(!empty(nv_socials()['instagram']) && !empty(nv_socials()['youtube']), 'Instagram and YouTube links are set');


/* ================================================================== 11. security */
nvt_section('Security');
wp_set_current_user(0);
$routes = rest_get_server()->get_routes();
nvt_ok(!isset($routes['/wp/v2/users']), 'Visitors cannot list users through the REST API');
nvt_ok(apply_filters('xmlrpc_enabled', true) === false, 'XML-RPC is off');
nvt_ok(get_author_posts_url(1) === home_url('/'), 'Author links do not reveal usernames');
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(10, 250);
for ($i = 0; $i < 8; $i++) do_action('wp_login_failed', 'nobody');
$r = apply_filters('authenticate', null, 'nobody', 'x');
nvt_ok(is_wp_error($r) && $r->get_error_code() === 'nvc_locked', 'Logins are locked after 8 wrong attempts');
delete_transient(nvc_login_key());
$r = apply_filters('authenticate', null, 'nobody', 'x');
nvt_ok(!is_wp_error($r) || $r->get_error_code() !== 'nvc_locked', 'Lock clears after reset');

/* ================================================================== cleanup */
nvt_section('Cleanup');
foreach (array_reverse($GLOBALS['nvt']['cleanup']) as $id) {
	$o = wc_get_order($id);
	if ($o) $o->delete(true);
}
$o2 && ($x = wc_get_order($o2->get_id())) && $x->delete(true);
if (!empty($GLOBALS['nvt']['user'])) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user($GLOBALS['nvt']['user']);
}
foreach ([$email, $email2] as $e) delete_transient(nvc_otp_key('email', $e));
$left = wc_get_orders(['meta_key' => '_nvt_test', 'meta_value' => '1', 'limit' => -1]);
nvt_ok(count($left) === 0, 'Test orders removed');
nvt_ok(!get_user_by('email', $email), 'Test customer removed');

/* ================================================================== summary */
if ($GLOBALS['nvt']['warnings']) {
	WP_CLI::log("\nPHP warnings from neemveda code:");
	foreach (array_unique($GLOBALS['nvt']['warnings']) as $w) WP_CLI::log("  ! $w");
}
WP_CLI::log(sprintf("\n%d passed, %d failed", $GLOBALS['nvt']['pass'], $GLOBALS['nvt']['fail']));
if ($GLOBALS['nvt']['fail'] || $GLOBALS['nvt']['warnings']) {
	foreach ($GLOBALS['nvt']['fails'] as $f) WP_CLI::log("  FAILED: $f");
	exit(1);
}
