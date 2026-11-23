<?php
/**
 * Welcome popup for new visitors: "Create your profile" or "Continue as guest".
 *
 * The profile form is WooCommerce's own sign-up (same fields and email OTP as My account → Register),
 * so a profile made here is a normal customer account. "Continue as guest" closes the popup and
 * remembers the choice for 30 days; closing it with × or Esc hides it for a day.
 * The popup never shows to logged-in users or on the cart, checkout and My account pages.
 *
 * Settings: WooCommerce → neemveda settings → Accounts.
 */
defined('ABSPATH') || exit;

const NVC_WELCOME_COOKIE = 'nv_welcome';

/** Guest checkout follows the neemveda setting (on by default). */
add_filter('pre_option_woocommerce_enable_guest_checkout', function () {
	return nvc_get('nv_guest_checkout') ? 'yes' : 'no';
});

function nvc_welcome_enabled() {
	return (bool) nvc_get('nv_welcome_on');
}

/** Whether the popup markup goes on this page (the script still checks the cookie, so cached pages work). */
function nvc_welcome_on_this_page() {
	if (is_admin() || is_user_logged_in() || !nvc_welcome_enabled() || !function_exists('is_account_page')) return false;
	if (is_account_page() || is_checkout() || is_cart()) return false;
	if (get_option('woocommerce_enable_myaccount_registration') !== 'yes') return false;
	return true;
}

/* ------------------------------------------------------------------ sign-up sent from the popup */
// WooCommerce handles the sign-up on wp_loaded (priority 20). If it failed, move its error messages
// into the popup (and out of the page) so the customer sees them where they typed.
$GLOBALS['nvc_welcome_errors'] = [];
add_action('wp_loaded', function () {
	if (empty($_POST['nv_welcome']) || empty($_POST['register']) || is_user_logged_in() || !function_exists('wc_get_notices')) return;
	$GLOBALS['nvc_welcome_errors'] = array_map(fn($n) => is_array($n) ? $n['notice'] : $n, wc_get_notices('error'));
	if ($GLOBALS['nvc_welcome_errors']) wc_clear_notices();
}, 21);

// After a successful sign-up from the popup, stay on the same page (or go to checkout if the cart has items).
add_filter('woocommerce_registration_redirect', function ($redirect) {
	if (empty($_POST['nv_welcome'])) return $redirect;
	if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) return wc_get_checkout_url();
	$back = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['nv_back'] ?? '')), home_url('/'));
	return add_query_arg('nv_welcome', 'done', $back);
}, 30);

/* ------------------------------------------------------------------ assets */
add_action('wp_enqueue_scripts', function () {
	if (!nvc_welcome_on_this_page() && empty($_GET['nv_welcome'])) return;
	wp_enqueue_style('nvc-welcome', NVC_URL . 'assets/welcome.css', [], NVC_VERSION);
	wp_enqueue_script('nvc-welcome', NVC_URL . 'assets/welcome.js', [], NVC_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
	wp_localize_script('nvc-welcome', 'NVC_WELCOME', [
		'cookie'   => NVC_WELCOME_COOKIE,
		'secure'   => is_ssl(),
		'openNow'  => !empty($GLOBALS['nvc_welcome_errors']),
		'created'  => ($_GET['nv_welcome'] ?? '') === 'done' && is_user_logged_in(),
		'delay'    => 3000, // let the page be seen first
	]);
}, 20);

/* ------------------------------------------------------------------ markup */
add_action('wp_footer', function () {
	if (!nvc_welcome_on_this_page()) return;
	$account = wc_get_page_permalink('myaccount');
	// Same page, as a path (works when WordPress lives in a sub-folder too).
	$back = remove_query_arg('nv_welcome', esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));
	$errors = $GLOBALS['nvc_welcome_errors'];
	$guest_ok = nvc_get('nv_guest_checkout');
	?>
	<dialog class="nv-welcome woocommerce" id="nv-welcome" aria-labelledby="nv-welcome-title" data-nv-welcome>
		<button type="button" class="nv-welcome__close" data-nv-welcome-close="later" aria-label="<?php esc_attr_e('Close', 'neemveda'); ?>">×</button>
		<div class="nv-welcome__head">
			<svg class="nv-welcome__leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
			<p class="nv-welcome__kicker"><?php esc_html_e('Welcome to neemveda', 'neemveda'); ?></p>
			<h2 id="nv-welcome-title"><?php esc_html_e('Create your profile', 'neemveda'); ?></h2>
			<p class="nv-welcome__lead"><?php esc_html_e('Save your details for a faster checkout, see all your orders in one place and track every delivery.', 'neemveda'); ?></p>
		</div>

		<?php if ($errors) : ?>
			<ul class="nv-welcome__errors" role="alert">
				<?php foreach ($errors as $e) : ?><li><?php echo wp_kses_post($e); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" class="woocommerce-form woocommerce-form-register register nv-welcome__form" action="<?php echo esc_url($back); ?>">
			<?php do_action('woocommerce_register_form_start'); ?>
			<p class="woocommerce-form-row form-row form-row-wide">
				<label for="reg_email"><?php esc_html_e('Email address', 'neemveda'); ?> <span class="required">*</span></label>
				<input type="email" class="input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo esc_attr(sanitize_email(wp_unslash($_POST['email'] ?? ''))); ?>" required>
			</p>
			<?php if (get_option('woocommerce_registration_generate_password') !== 'yes') : ?>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="reg_password"><?php esc_html_e('Create a password', 'neemveda'); ?> <span class="required">*</span></label>
					<input type="password" class="input-text" name="password" id="reg_password" autocomplete="new-password" required>
				</p>
			<?php endif; ?>
			<?php if (get_option('woocommerce_registration_generate_username') !== 'yes') : ?>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="reg_username"><?php esc_html_e('Username', 'neemveda'); ?> <span class="required">*</span></label>
					<input type="text" class="input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo esc_attr(sanitize_user(wp_unslash($_POST['username'] ?? ''))); ?>" required>
				</p>
			<?php endif; ?>
			<?php do_action('woocommerce_register_form'); ?>
			<input type="hidden" name="nv_welcome" value="1">
			<input type="hidden" name="nv_back" value="<?php echo esc_url($back); ?>">
			<?php wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce'); ?>
			<button type="submit" class="btn btn-primary btn-block" name="register" value="<?php esc_attr_e('Register', 'neemveda'); ?>"><?php esc_html_e('Create my profile', 'neemveda'); ?></button>
			<?php do_action('woocommerce_register_form_end'); ?>
		</form>

		<div class="nv-welcome__or"><span><?php esc_html_e('or', 'neemveda'); ?></span></div>
		<button type="button" class="btn btn-outline btn-block" data-nv-welcome-close="guest"><?php esc_html_e('Continue as guest', 'neemveda'); ?></button>
		<p class="nv-welcome__note">
			<?php echo $guest_ok
				? esc_html__('You can still shop and order without a profile.', 'neemveda')
				: esc_html__('You can look around as a guest. You’ll need a profile to place an order.', 'neemveda'); ?>
			<br><?php esc_html_e('Already have a profile?', 'neemveda'); ?> <a href="<?php echo esc_url($account); ?>"><?php esc_html_e('Log in', 'neemveda'); ?></a>
		</p>
	</dialog>
	<?php
}, 30);
