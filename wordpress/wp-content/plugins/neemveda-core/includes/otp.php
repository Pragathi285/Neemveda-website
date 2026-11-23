<?php
/**
 * OTP verification.
 *
 * Sign up (My account → Register, or the welcome popup): the customer verifies their email with a 6-digit
 * code, and their mobile number too once an SMS provider is set up. Customers can also order as guests
 * (neemveda settings → Accounts). At checkout, a mobile number that hasn't been verified yet must be
 * verified (when SMS is on), for guests and logged-in customers alike.
 *
 * Codes are 6 digits, valid 10 minutes, stored hashed; 5 wrong tries per code; one send per 60 s and
 * at most 6 per hour per address, plus a per-IP limit. A successful check returns a signed token that
 * the form submits, so nothing depends on PHP sessions.
 */
defined('ABSPATH') || exit;

const NVC_OTP_TTL = 600;
const NVC_TOKEN_TTL = 1800;

function nvc_otp_key($channel, $value) {
	return 'nvc_otp_' . md5($channel . '|' . strtolower($value));
}

function nvc_client_ip() {
	// Behind the Traefik proxy the real client is the last address the proxy appended.
	$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
	if ($xff) {
		$parts = array_map('trim', explode(',', $xff));
		return sanitize_text_field(end($parts));
	}
	return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Simple counter per key in a transient. Returns false once $max is exceeded in $window seconds. */
function nvc_rate_ok($key, $max, $window) {
	$k = 'nvc_rl_' . md5($key);
	$n = (int) get_transient($k);
	if ($n >= $max) return false;
	set_transient($k, $n + 1, $window);
	return true;
}

function nvc_otp_token($channel, $value) {
	$payload = base64_encode(wp_json_encode(['c' => $channel, 'v' => strtolower($value), 'e' => time() + NVC_TOKEN_TTL]));
	return $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
}

function nvc_otp_token_valid($token, $channel, $value) {
	$parts = explode('.', (string) $token);
	if (count($parts) !== 2) return false;
	[$payload, $sig] = $parts;
	if (!hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $sig)) return false;
	$data = json_decode(base64_decode($payload), true);
	return is_array($data) && $data['c'] === $channel && $data['v'] === strtolower($value) && $data['e'] > time();
}

/* ------------------------------------------------------------------ AJAX: send and check codes */
add_action('wp_ajax_nvc_otp_send', 'nvc_ajax_otp_send');
add_action('wp_ajax_nopriv_nvc_otp_send', 'nvc_ajax_otp_send');
function nvc_ajax_otp_send() {
	check_ajax_referer('nvc_otp', 'nonce');
	$channel = sanitize_key($_POST['channel'] ?? '');
	$raw = wp_unslash($_POST['value'] ?? '');
	if ($channel === 'email') {
		$value = sanitize_email($raw);
		if (!is_email($value)) wp_send_json_error(['message' => __('Please enter a valid email address.', 'neemveda')]);
		if (($_POST['purpose'] ?? '') === 'register' && email_exists($value)) {
			wp_send_json_error(['message' => __('This email already has an account. Please log in instead.', 'neemveda')]);
		}
	} elseif ($channel === 'phone') {
		if (!nvc_sms_ready()) wp_send_json_error(['message' => __('Phone verification is not available yet.', 'neemveda')]);
		$value = nvc_normalise_phone($raw);
		if (!$value) wp_send_json_error(['message' => __('Please enter a valid 10-digit Indian mobile number.', 'neemveda')]);
	} else {
		wp_send_json_error(['message' => 'Bad request.']);
	}

	$key = nvc_otp_key($channel, $value);
	$existing = get_transient($key);
	if ($existing && $existing['sent'] > time() - 60) {
		wp_send_json_error(['message' => __('Please wait a minute before asking for a new code.', 'neemveda'), 'wait' => 60 - (time() - $existing['sent'])]);
	}
	if (!nvc_rate_ok('send|' . $channel . '|' . $value, 6, HOUR_IN_SECONDS) || !nvc_rate_ok('ip|' . nvc_client_ip(), 25, HOUR_IN_SECONDS)) {
		wp_send_json_error(['message' => __('Too many codes requested. Please try again in an hour.', 'neemveda')]);
	}

	$code = (string) random_int(100000, 999999);
	if ($channel === 'email') {
		$sent = nvc_send_wc_mail(
			$value,
			sprintf(__('%s is your neemveda verification code', 'neemveda'), $code),
			__('Verify your email', 'neemveda'),
			'<p>' . esc_html__('Use this code to verify your email address on neemveda:', 'neemveda') . '</p>' .
			'<p style="font-size:30px;letter-spacing:8px;font-weight:bold;color:#2F4F2F">' . esc_html($code) . '</p>' .
			'<p>' . esc_html__('The code expires in 10 minutes. If you did not ask for it, you can ignore this email.', 'neemveda') . '</p>'
		);
		if (!$sent) wp_send_json_error(['message' => __('We could not send the email right now. Please try again shortly.', 'neemveda')]);
	} else {
		$res = nvc_send_sms_otp($value, $code);
		if (is_wp_error($res)) {
			error_log('[neemveda] SMS OTP failed: ' . $res->get_error_message());
			wp_send_json_error(['message' => __('We could not send the SMS right now. Please try again shortly.', 'neemveda')]);
		}
	}
	set_transient($key, ['hash' => wp_hash_password($code), 'sent' => time(), 'tries' => 0, 'exp' => time() + NVC_OTP_TTL], NVC_OTP_TTL);
	wp_send_json_success(['message' => $channel === 'email'
		? sprintf(__('Code sent to %s. Check your inbox (and spam).', 'neemveda'), $value)
		: sprintf(__('Code sent by SMS to %s.', 'neemveda'), $value)]);
}

add_action('wp_ajax_nvc_otp_verify', 'nvc_ajax_otp_verify');
add_action('wp_ajax_nopriv_nvc_otp_verify', 'nvc_ajax_otp_verify');
function nvc_ajax_otp_verify() {
	check_ajax_referer('nvc_otp', 'nonce');
	$channel = sanitize_key($_POST['channel'] ?? '');
	$raw = wp_unslash($_POST['value'] ?? '');
	$value = $channel === 'email' ? sanitize_email($raw) : nvc_normalise_phone($raw);
	$code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
	if (!$value || strlen($code) !== 6) wp_send_json_error(['message' => __('Please enter the 6-digit code.', 'neemveda')]);

	$key = nvc_otp_key($channel, $value);
	$data = get_transient($key);
	if (!$data || $data['exp'] < time()) wp_send_json_error(['message' => __('This code has expired. Please ask for a new one.', 'neemveda')]);
	if ($data['tries'] >= 5) {
		delete_transient($key);
		wp_send_json_error(['message' => __('Too many wrong tries. Please ask for a new code.', 'neemveda')]);
	}
	if (!wp_check_password($code, $data['hash'])) {
		$data['tries']++;
		set_transient($key, $data, max(1, $data['exp'] - time()));
		wp_send_json_error(['message' => __('That code is not right. Please check and try again.', 'neemveda')]);
	}
	delete_transient($key);
	if ($channel === 'phone' && is_user_logged_in()) {
		update_user_meta(get_current_user_id(), 'nv_phone_verified', $value);
	}
	wp_send_json_success(['token' => nvc_otp_token($channel, $value), 'message' => __('Verified ✓', 'neemveda')]);
}

/* ------------------------------------------------------------------ front-end widget */
add_action('wp_enqueue_scripts', function () {
	if (!function_exists('is_account_page')) return;
	$welcome = function_exists('nvc_welcome_on_this_page') && nvc_welcome_on_this_page();
	if (!(is_account_page() || is_checkout() || $welcome)) return;
	wp_enqueue_script('nvc-otp', NVC_URL . 'assets/otp.js', [], NVC_VERSION, ['in_footer' => true]);
	wp_localize_script('nvc-otp', 'NVC_OTP', [
		'ajax'  => admin_url('admin-ajax.php'),
		'nonce' => wp_create_nonce('nvc_otp'),
	]);
});

/** One OTP box: "Send code" → enter code → "Verify". $input is the CSS selector of the email/phone field it checks. */
function nvc_otp_widget($channel, $input, $purpose, $label) {
	$name = 'nv_' . $channel . '_token';
	?>
	<div class="nv-otp" data-nv-otp data-channel="<?php echo esc_attr($channel); ?>" data-input="<?php echo esc_attr($input); ?>" data-purpose="<?php echo esc_attr($purpose); ?>">
		<strong><?php echo esc_html($label); ?></strong>
		<div class="nv-otp__row">
			<button type="button" class="button" data-otp-send><?php esc_html_e('Send code', 'neemveda'); ?></button>
			<input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code" aria-label="<?php esc_attr_e('Verification code', 'neemveda'); ?>" data-otp-code>
			<button type="button" class="button" data-otp-verify><?php esc_html_e('Verify', 'neemveda'); ?></button>
		</div>
		<p class="nv-otp__msg" data-otp-msg role="status" aria-live="polite"></p>
		<input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr(wp_unslash($_POST[$name] ?? '')); ?>" data-otp-token>
	</div>
	<?php
}

/* ------------------------------------------------------------------ registration form */
add_action('woocommerce_register_form_start', function () {
	?>
	<p class="woocommerce-form-row form-row form-row-wide">
		<label for="nv_reg_name"><?php esc_html_e('Full name', 'neemveda'); ?> <span class="required">*</span></label>
		<input type="text" class="input-text" name="nv_name" id="nv_reg_name" autocomplete="name" value="<?php echo esc_attr(wp_unslash($_POST['nv_name'] ?? '')); ?>" required>
	</p>
	<p class="woocommerce-form-row form-row form-row-wide">
		<label for="nv_reg_phone"><?php esc_html_e('Mobile number', 'neemveda'); ?> <span class="required">*</span></label>
		<input type="tel" class="input-text" name="nv_phone" id="nv_reg_phone" autocomplete="tel" inputmode="tel" placeholder="10-digit mobile" value="<?php echo esc_attr(wp_unslash($_POST['nv_phone'] ?? '')); ?>" required>
	</p>
	<?php
});

add_action('woocommerce_register_form', function () {
	nvc_otp_widget('email', '#reg_email', 'register', __('Verify your email', 'neemveda'));
	if (nvc_sms_ready()) {
		nvc_otp_widget('phone', '#nv_reg_phone', 'register', __('Verify your mobile number', 'neemveda'));
	}
}, 5);

add_filter('woocommerce_process_registration_errors', function ($errors, $username, $password, $email) {
	$name = sanitize_text_field(wp_unslash($_POST['nv_name'] ?? ''));
	$phone = nvc_normalise_phone(wp_unslash($_POST['nv_phone'] ?? ''));
	if ($name === '') $errors->add('nv_name', __('Please enter your full name.', 'neemveda'));
	if (!$phone) $errors->add('nv_phone', __('Please enter a valid 10-digit Indian mobile number.', 'neemveda'));
	if (!nvc_otp_token_valid(wp_unslash($_POST['nv_email_token'] ?? ''), 'email', $email)) {
		$errors->add('nv_email_otp', __('Please verify your email with the code we send you.', 'neemveda'));
	}
	if ($phone && nvc_sms_ready() && !nvc_otp_token_valid(wp_unslash($_POST['nv_phone_token'] ?? ''), 'phone', $phone)) {
		$errors->add('nv_phone_otp', __('Please verify your mobile number with the SMS code.', 'neemveda'));
	}
	return $errors;
}, 10, 4);

add_action('woocommerce_created_customer', function ($user_id) {
	$name = sanitize_text_field(wp_unslash($_POST['nv_name'] ?? ''));
	$phone = nvc_normalise_phone(wp_unslash($_POST['nv_phone'] ?? ''));
	if ($name) {
		$parts = preg_split('/\s+/', $name, 2);
		update_user_meta($user_id, 'first_name', $parts[0]);
		update_user_meta($user_id, 'billing_first_name', $parts[0]);
		if (!empty($parts[1])) {
			update_user_meta($user_id, 'last_name', $parts[1]);
			update_user_meta($user_id, 'billing_last_name', $parts[1]);
		}
		wp_update_user(['ID' => $user_id, 'display_name' => $name]);
	}
	if ($phone) {
		update_user_meta($user_id, 'billing_phone', $phone);
		if (nvc_sms_ready()) update_user_meta($user_id, 'nv_phone_verified', $phone);
	}
	update_user_meta($user_id, 'nv_email_verified', '1');
});

/* ------------------------------------------------------------------ checkout: guest or account, phone check */
// WooCommerce prints this message escaped, so show our own box (with a button) and leave the message empty.
add_filter('woocommerce_checkout_must_be_logged_in_message', fn() => '');
add_action('woocommerce_before_checkout_form', function () {
	if (is_user_logged_in()) return;
	$url = wc_get_page_permalink('myaccount');
	if (get_option('woocommerce_enable_guest_checkout') === 'yes') {
		// Guests can order; just offer the profile.
		echo '<div class="woocommerce-info nv-login-needed nv-guest-note"><p><strong>' . esc_html__('Ordering as a guest.', 'neemveda') . '</strong> ' .
			esc_html__('Fill in your details below. Want your orders saved in one place? Create a free profile.', 'neemveda') .
			'</p><p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Log in / Create profile', 'neemveda') . '</a></p></div>';
		return;
	}
	echo '<div class="woocommerce-info nv-login-needed"><p><strong>' . esc_html__('Please log in or create an account to place your order.', 'neemveda') . '</strong><br>' .
		esc_html__('It takes a minute: verify your email with a one-time code, then you can see your order history and track every order.', 'neemveda') .
		'</p><p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Log in / Sign up', 'neemveda') . '</a></p></div>';
}, 5);

// After logging in or signing up with items in the cart, go straight back to checkout.
$nvc_back_to_checkout = function ($redirect) {
	return (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) ? wc_get_checkout_url() : $redirect;
};
add_filter('woocommerce_login_redirect', $nvc_back_to_checkout, 20);
add_filter('woocommerce_registration_redirect', $nvc_back_to_checkout, 20);

add_action('woocommerce_after_checkout_billing_form', function () {
	if (!nvc_sms_ready()) return;
	$verified = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'nv_phone_verified', true) : '';
	echo '<div data-nv-phone-otp-wrap data-verified="' . esc_attr($verified) . '">';
	nvc_otp_widget('phone', '#billing_phone', 'checkout', __('Verify this mobile number (one-time)', 'neemveda'));
	echo '</div>';
}, 5);

add_action('woocommerce_checkout_process', function () {
	$raw = trim((string) wp_unslash($_POST['billing_phone'] ?? ''));
	if ($raw === '') return; // WooCommerce already says "Phone is a required field"; one message is enough.
	$phone = nvc_normalise_phone($raw);
	if (!$phone) {
		wc_add_notice(__('Please enter a valid 10-digit Indian mobile number.', 'neemveda'), 'error');
		return;
	}
	if (!nvc_sms_ready()) return;
	$verified = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'nv_phone_verified', true) : '';
	if ($verified !== $phone && !nvc_otp_token_valid(wp_unslash($_POST['nv_phone_token'] ?? ''), 'phone', $phone)) {
		wc_add_notice(__('Please verify your mobile number with the SMS code before placing the order.', 'neemveda'), 'error');
	}
});

// Show verification status to the shop owner on the user profile.
add_action('show_user_profile', 'nvc_user_verification_row');
add_action('edit_user_profile', 'nvc_user_verification_row');
function nvc_user_verification_row($user) {
	$email = get_user_meta($user->ID, 'nv_email_verified', true);
	$phone = get_user_meta($user->ID, 'nv_phone_verified', true);
	echo '<h2>neemveda verification</h2><table class="form-table"><tr><th>Email</th><td>' . ($email ? '✓ verified' : 'not verified') .
		'</td></tr><tr><th>Mobile</th><td>' . ($phone ? '✓ verified (' . esc_html($phone) . ')' : 'not verified') . '</td></tr></table>';
}
