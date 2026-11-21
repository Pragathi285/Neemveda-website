<?php
/**
 * Shop extras: "Buy now", free delivery over a threshold, product fields (sticks per pack, badge),
 * contact form ([neemveda_contact]) with messages in the admin, and the newsletter sign-up list.
 */
defined('ABSPATH') || exit;

/* ------------------------------------------------------------------ Buy now */
add_action('woocommerce_after_add_to_cart_button', function () {
	global $product;
	if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) return;
	echo '<button type="submit" name="add-to-cart" value="' . esc_attr($product->get_id()) . '" class="single_add_to_cart_button button alt nv-buy-now" formaction="' .
		esc_url(add_query_arg('nv_buy_now', '1', $product->get_permalink())) . '">' . esc_html__('Buy now', 'neemveda') . '</button>';
});

add_filter('woocommerce_add_to_cart_redirect', function ($url) {
	return !empty($_REQUEST['nv_buy_now']) ? wc_get_checkout_url() : $url;
});

add_action('woocommerce_single_product_summary', function () {
	echo '<ul class="nv-product-points">' .
		'<li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>' . esc_html__('100% natural neem twigs, hand-cut', 'neemveda') . '</li>' .
		'<li><svg class="icon" aria-hidden="true"><use href="#i-truck"/></svg>' . sprintf(esc_html__('Free delivery on orders of %s+', 'neemveda'), esc_html(wp_strip_all_tags(wc_price((int) nvc_get('nv_free_shipping_min'), ['decimals' => 0])))) . '</li>' .
		'<li><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>' . esc_html__('Cash on Delivery available', 'neemveda') . '</li>' .
		'</ul>';
}, 35);

/* ------------------------------------------------------------------ delivery charge */
// When free delivery applies, don't also offer the paid flat rate.
add_filter('woocommerce_package_rates', function ($rates) {
	$free = array_filter($rates, fn($r) => $r->get_method_id() === 'free_shipping');
	return $free ?: $rates;
}, 100);

/* ------------------------------------------------------------------ product fields */
add_action('woocommerce_product_options_general_product_data', function () {
	woocommerce_wp_text_input(['id' => 'nv_count', 'label' => __('Sticks per pack', 'neemveda'), 'type' => 'number', 'custom_attributes' => ['min' => 1]]);
	woocommerce_wp_text_input(['id' => 'nv_badge', 'label' => __('Badge (e.g. Bestseller)', 'neemveda')]);
});
add_action('woocommerce_admin_process_product_object', function ($product) {
	if (isset($_POST['nv_count'])) $product->update_meta_data('nv_count', absint($_POST['nv_count']));
	if (isset($_POST['nv_badge'])) $product->update_meta_data('nv_badge', sanitize_text_field(wp_unslash($_POST['nv_badge'])));
});

/* ------------------------------------------------------------------ contact form */
add_action('init', function () {
	register_post_type('nv_message', [
		'label'           => __('Messages', 'neemveda'),
		'labels'          => ['name' => __('Messages', 'neemveda'), 'singular_name' => __('Message', 'neemveda')],
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'woocommerce',
		'supports'        => ['title', 'editor'],
		'capability_type' => 'post',
		'capabilities'    => ['create_posts' => 'do_not_allow'],
		'map_meta_cap'    => true,
	]);
});

add_shortcode('neemveda_contact', function () {
	ob_start();
	if (isset($_GET['nv_sent'])) {
		echo '<p class="woocommerce-message" role="status">' . esc_html__('Thank you! We have received your message and will reply within 1–2 working days.', 'neemveda') . '</p>';
	} elseif (isset($_GET['nv_error'])) {
		echo '<p class="woocommerce-error" role="alert">' . esc_html__('Please fill in your name, a valid email and your message.', 'neemveda') . '</p>';
	}
	?>
	<form class="nv-contact-form card contact-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="nv_contact">
		<?php wp_nonce_field('nv_contact', 'nv_nonce'); ?>
		<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave empty<input name="nv_website" tabindex="-1" autocomplete="off"></label></p>
		<label for="nv_c_name"><?php esc_html_e('Your name', 'neemveda'); ?></label>
		<input class="input" id="nv_c_name" name="name" autocomplete="name" required>
		<label for="nv_c_email"><?php esc_html_e('Email', 'neemveda'); ?></label>
		<input class="input" id="nv_c_email" name="email" type="email" autocomplete="email" required>
		<label for="nv_c_topic"><?php esc_html_e('Topic', 'neemveda'); ?></label>
		<select class="select" id="nv_c_topic" name="topic">
			<option><?php esc_html_e('Question about neem sticks', 'neemveda'); ?></option>
			<option><?php esc_html_e('My order', 'neemveda'); ?></option>
			<option><?php esc_html_e('Bulk or gifting enquiry', 'neemveda'); ?></option>
			<option><?php esc_html_e('Retail / wholesale partnership', 'neemveda'); ?></option>
			<option><?php esc_html_e('Something else', 'neemveda'); ?></option>
		</select>
		<label for="nv_c_msg"><?php esc_html_e('Message', 'neemveda'); ?></label>
		<textarea class="textarea" id="nv_c_msg" name="message" required></textarea>
		<button class="btn btn-primary" type="submit"><?php esc_html_e('Send message', 'neemveda'); ?></button>
	</form>
	<?php
	return ob_get_clean();
});

$nvc_contact = function () {
	$back = wp_get_referer() ?: home_url('/contact/');
	if (!isset($_POST['nv_nonce']) || !wp_verify_nonce($_POST['nv_nonce'], 'nv_contact') || !empty($_POST['nv_website'])) {
		wp_safe_redirect($back);
		exit;
	}
	$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
	$email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
	$topic = sanitize_text_field(wp_unslash($_POST['topic'] ?? ''));
	$msg = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
	if (!$name || !is_email($email) || !$msg || !nvc_rate_ok('contact|' . nvc_client_ip(), 8, HOUR_IN_SECONDS)) {
		wp_safe_redirect(add_query_arg('nv_error', '1', remove_query_arg('nv_sent', $back)));
		exit;
	}
	wp_insert_post([
		'post_type'    => 'nv_message',
		'post_status'  => 'private',
		'post_title'   => sprintf('%s <%s> · %s', $name, $email, $topic),
		'post_content' => $msg,
	]);
	$to = nvc_get('nv_alert_email') ?: get_option('admin_email');
	wp_mail($to, sprintf('Website message from %s (%s)', $name, $topic), $msg . "\n\nName: $name\nEmail: $email\nTopic: $topic", ['Reply-To: ' . $name . ' <' . $email . '>']);
	wp_safe_redirect(add_query_arg('nv_sent', '1', remove_query_arg('nv_error', $back)));
	exit;
};
add_action('admin_post_nv_contact', $nvc_contact);
add_action('admin_post_nopriv_nv_contact', $nvc_contact);

/* ------------------------------------------------------------------ newsletter */
$nvc_newsletter = function () {
	$back = wp_get_referer() ?: home_url('/');
	if (isset($_POST['nv_nonce']) && wp_verify_nonce($_POST['nv_nonce'], 'nv_newsletter') && nvc_rate_ok('nl|' . nvc_client_ip(), 10, HOUR_IN_SECONDS)) {
		$email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
		if (is_email($email)) {
			$list = get_option('nv_subscribers', []);
			if (!isset($list[$email])) {
				$list[$email] = time();
				update_option('nv_subscribers', $list, false);
			}
			wp_safe_redirect(add_query_arg('nv_subscribed', '1', $back) . '#newsletter-title');
			exit;
		}
	}
	wp_safe_redirect($back);
	exit;
};
add_action('admin_post_nv_newsletter', $nvc_newsletter);
add_action('admin_post_nopriv_nv_newsletter', $nvc_newsletter);

add_action('admin_menu', function () {
	add_submenu_page('woocommerce', __('Newsletter', 'neemveda'), __('Newsletter', 'neemveda'), 'manage_woocommerce', 'nv-newsletter', function () {
		$list = get_option('nv_subscribers', []);
		if (isset($_GET['csv']) && check_admin_referer('nv_csv')) {
			// handled in admin_init below
		}
		echo '<div class="wrap"><h1>Newsletter subscribers (' . count($list) . ')</h1>';
		echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=nv-newsletter&csv=1'), 'nv_csv')) . '">Download CSV</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Joined</th></tr></thead><tbody>';
		foreach (array_reverse($list, true) as $email => $at) {
			echo '<tr><td>' . esc_html($email) . '</td><td>' . esc_html(wp_date('j M Y, g:i a', $at)) . '</td></tr>';
		}
		if (!$list) echo '<tr><td colspan="2">No subscribers yet.</td></tr>';
		echo '</tbody></table></div>';
	});
}, 70);

add_action('admin_init', function () {
	if (($_GET['page'] ?? '') !== 'nv-newsletter' || empty($_GET['csv']) || !current_user_can('manage_woocommerce')) return;
	check_admin_referer('nv_csv');
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="neemveda-subscribers.csv"');
	$out = fopen('php://output', 'w');
	fputcsv($out, ['Email', 'Joined']);
	foreach (get_option('nv_subscribers', []) as $email => $at) fputcsv($out, [$email, wp_date('Y-m-d H:i', $at)]);
	exit;
});

/* Keep WooCommerce's free-delivery minimum in step with "Free delivery from (₹)" in neemveda settings. */
add_action('update_option_nv_free_shipping_min', function ($old, $new) {
	if (!class_exists('WC_Shipping_Zones')) return;
	foreach (WC_Shipping_Zones::get_zones() as $z) {
		foreach ((new WC_Shipping_Zone($z['id']))->get_shipping_methods() as $m) {
			if ($m->id !== 'free_shipping') continue;
			$key = 'woocommerce_free_shipping_' . $m->instance_id . '_settings';
			$s = get_option($key, []);
			$s['min_amount'] = (string) max(0, (int) $new);
			$s['requires'] = 'min_amount';
			update_option($key, $s);
		}
	}
	WC_Cache_Helper::get_transient_version('shipping', true);
}, 10, 2);
