<?php
/**
 * Outgoing email through SMTP (Brevo). Settings come from wp-config.php constants:
 *   NV_SMTP_HOST, NV_SMTP_PORT, NV_SMTP_USER, NV_SMTP_PASS, NV_SMTP_SECURE ('tls' | 'ssl' | ''),
 *   NV_MAIL_FROM, NV_MAIL_FROM_NAME
 * (the Docker setup fills these from environment variables).
 */
defined('ABSPATH') || exit;

function nvc_mail_ready() {
	return defined('NV_SMTP_HOST') && NV_SMTP_HOST && defined('NV_SMTP_PASS') && NV_SMTP_PASS;
}

add_action('phpmailer_init', function ($mailer) {
	if (!nvc_mail_ready()) return;
	$mailer->isSMTP();
	$mailer->Host = NV_SMTP_HOST;
	$mailer->Port = defined('NV_SMTP_PORT') ? (int) NV_SMTP_PORT : 587;
	$mailer->SMTPAuth = true;
	$mailer->Username = defined('NV_SMTP_USER') ? NV_SMTP_USER : '';
	$mailer->Password = NV_SMTP_PASS;
	$secure = defined('NV_SMTP_SECURE') ? NV_SMTP_SECURE : 'tls';
	if ($secure) $mailer->SMTPSecure = $secure;
	if (defined('NV_MAIL_FROM') && NV_MAIL_FROM) {
		$mailer->setFrom(NV_MAIL_FROM, defined('NV_MAIL_FROM_NAME') ? NV_MAIL_FROM_NAME : 'neemveda', false);
	}
});

// WooCommerce and WordPress emails use the same sender.
add_filter('wp_mail_from', fn($from) => (defined('NV_MAIL_FROM') && NV_MAIL_FROM) ? NV_MAIL_FROM : $from);
add_filter('wp_mail_from_name', fn($name) => defined('NV_MAIL_FROM_NAME') ? NV_MAIL_FROM_NAME : 'neemveda');
add_filter('woocommerce_email_from_address', fn($from) => (defined('NV_MAIL_FROM') && NV_MAIL_FROM) ? NV_MAIL_FROM : $from);
add_filter('woocommerce_email_from_name', fn($name) => defined('NV_MAIL_FROM_NAME') ? NV_MAIL_FROM_NAME : 'neemveda');

add_action('wp_mail_failed', function ($error) {
	error_log('[neemveda] email failed: ' . $error->get_error_message());
});

/** Send an email in WooCommerce's branded template. */
function nvc_send_wc_mail($to, $subject, $heading, $html_body) {
	if (!function_exists('WC')) return wp_mail($to, $subject, wp_strip_all_tags($html_body));
	$mailer = WC()->mailer();
	$message = $mailer->wrap_message($heading, $html_body);
	return $mailer->send($to, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
