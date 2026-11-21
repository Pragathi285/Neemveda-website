<?php
/**
 * Order tracking.
 *
 * Statuses: Processing (shown as "Confirmed") → Packed → Shipped → Out for delivery → Completed (shown as "Delivered").
 * On the order page the shop owner fills in the courier, tracking number/link, expected delivery date and
 * location updates ("Reached Chennai hub"). The customer is emailed (and pushed, if OneSignal is set up) at
 * each step, gets a reminder the day before the expected date, and sees a timeline in My account → Orders
 * and on the Track order page ([neemveda_track]).
 */
defined('ABSPATH') || exit;

function nvc_custom_statuses() {
	return [
		'wc-packed'           => _x('Packed', 'Order status', 'neemveda'),
		'wc-shipped'          => _x('Shipped', 'Order status', 'neemveda'),
		'wc-out-for-delivery' => _x('Out for delivery', 'Order status', 'neemveda'),
	];
}

add_action('init', function () {
	foreach (nvc_custom_statuses() as $key => $label) {
		register_post_status($key, [
			'label'                     => $label,
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'neemveda'),
		]);
	}
});

add_filter('wc_order_statuses', function ($statuses) {
	$out = [];
	foreach ($statuses as $key => $label) {
		if ($key === 'wc-processing') $label = _x('Confirmed (processing)', 'Order status', 'neemveda');
		if ($key === 'wc-completed') $label = _x('Delivered', 'Order status', 'neemveda');
		$out[$key] = $label;
		if ($key === 'wc-processing') $out += nvc_custom_statuses();
	}
	return $out;
});

// Custom statuses still count as paid/active orders (reports, "My account" actions, stock).
add_filter('woocommerce_order_is_paid_statuses', fn($s) => array_merge($s, ['packed', 'shipped', 'out-for-delivery']));
add_filter('woocommerce_reports_order_statuses', fn($s) => is_array($s) ? array_merge($s, ['packed', 'shipped', 'out-for-delivery']) : $s);

// Bulk actions in the orders list.
foreach (['bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders'] as $hook) {
	add_filter($hook, function ($actions) {
		$actions['mark_packed'] = __('Change status to packed', 'neemveda');
		$actions['mark_shipped'] = __('Change status to shipped', 'neemveda');
		$actions['mark_out-for-delivery'] = __('Change status to out for delivery', 'neemveda');
		return $actions;
	});
}

/* ------------------------------------------------------------------ steps for the customer timeline */
function nvc_track_steps() {
	return [
		'placed'           => __('Order placed', 'neemveda'),
		'processing'       => __('Confirmed', 'neemveda'),
		'packed'           => __('Packed', 'neemveda'),
		'shipped'          => __('Shipped', 'neemveda'),
		'out-for-delivery' => __('Out for delivery', 'neemveda'),
		'completed'        => __('Delivered', 'neemveda'),
	];
}

function nvc_status_label($status) {
	$labels = wc_get_order_statuses();
	return $labels['wc-' . $status] ?? ucfirst($status);
}

function nvc_fmt_date($ymd) {
	if (!$ymd) return '';
	$ts = strtotime($ymd . ' 12:00:00');
	return $ts ? wp_date('l, j F Y', $ts) : $ymd;
}

function nvc_log($order, $label, $place = '') {
	$log = $order->get_meta('_nv_track_log');
	$log = is_array($log) ? $log : [];
	$log[] = ['at' => time(), 'label' => $label, 'place' => $place];
	$order->update_meta_data('_nv_track_log', $log);
}

/* ------------------------------------------------------------------ admin: tracking box */
add_action('add_meta_boxes', function () {
	$screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
	add_meta_box('nv-tracking', __('Delivery & tracking (neemveda)', 'neemveda'), 'nvc_tracking_box', $screen, 'normal', 'high');
});

function nvc_tracking_box($post_or_order) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
	if (!$order) return;
	wp_nonce_field('nvc_tracking', 'nvc_tracking_nonce');
	$f = fn($k) => esc_attr($order->get_meta($k));
	$log = $order->get_meta('_nv_track_log');
	?>
	<style>.nv-tb{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.nv-tb label{display:block;font-weight:600;margin-bottom:4px}.nv-tb input{width:100%}.nv-log{margin:12px 0 0;padding-left:18px}</style>
	<div class="nv-tb">
		<p><label for="nv_courier"><?php esc_html_e('Courier', 'neemveda'); ?></label><input id="nv_courier" name="nv_courier" value="<?php echo $f('_nv_courier'); ?>" placeholder="DTDC / India Post / Delhivery"></p>
		<p><label for="nv_tracking_no"><?php esc_html_e('Tracking number', 'neemveda'); ?></label><input id="nv_tracking_no" name="nv_tracking_no" value="<?php echo $f('_nv_tracking_no'); ?>"></p>
		<p><label for="nv_tracking_url"><?php esc_html_e('Tracking link (optional)', 'neemveda'); ?></label><input id="nv_tracking_url" name="nv_tracking_url" type="url" value="<?php echo $f('_nv_tracking_url'); ?>" placeholder="https://"></p>
		<p><label for="nv_expected"><?php esc_html_e('Expected delivery date', 'neemveda'); ?></label><input id="nv_expected" name="nv_expected" type="date" value="<?php echo $f('_nv_expected'); ?>"></p>
		<p style="grid-column:1/-1"><label for="nv_place"><?php esc_html_e('Add a location update', 'neemveda'); ?></label><input id="nv_place" name="nv_place" placeholder="<?php esc_attr_e('e.g. Dispatched from Coimbatore / Reached Chennai hub', 'neemveda'); ?>">
			<?php if ($order->get_meta('_nv_place')) : ?><span class="description"><?php echo esc_html__('Last update: ', 'neemveda') . esc_html($order->get_meta('_nv_place')); ?></span><?php endif; ?></p>
	</div>
	<p><label><input type="checkbox" name="nv_notify" value="1" checked> <?php esc_html_e('Email the customer about this update (and send a push notification if set up)', 'neemveda'); ?></label></p>
	<p class="description"><?php esc_html_e('Change the order status at the top (Packed → Shipped → Out for delivery → Delivered) and click Update. The day before the expected date, the customer gets a reminder automatically.', 'neemveda'); ?></p>
	<?php if (is_array($log) && $log) : ?>
		<strong><?php esc_html_e('Tracking history', 'neemveda'); ?></strong>
		<ol class="nv-log"><?php foreach (array_reverse($log) as $row) : ?>
			<li><?php echo esc_html(wp_date('j M Y, g:i a', $row['at']) . ' · ' . $row['label'] . ($row['place'] ? ' · 📍 ' . $row['place'] : '')); ?></li>
		<?php endforeach; ?></ol>
	<?php endif;
}

// Runs before WooCommerce applies the status change (priority 40), so status emails already include these details.
add_action('woocommerce_process_shop_order_meta', 'nvc_save_tracking_box', 30);
function nvc_save_tracking_box($order_id) {
	if (!isset($_POST['nvc_tracking_nonce']) || !wp_verify_nonce($_POST['nvc_tracking_nonce'], 'nvc_tracking')) return;
	$order = wc_get_order($order_id);
	if (!$order) return;

	$old_expected = $order->get_meta('_nv_expected');
	$expected = sanitize_text_field(wp_unslash($_POST['nv_expected'] ?? ''));
	if ($expected && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected)) $expected = '';
	$place = sanitize_text_field(wp_unslash($_POST['nv_place'] ?? ''));

	$order->update_meta_data('_nv_courier', sanitize_text_field(wp_unslash($_POST['nv_courier'] ?? '')));
	$order->update_meta_data('_nv_tracking_no', sanitize_text_field(wp_unslash($_POST['nv_tracking_no'] ?? '')));
	$order->update_meta_data('_nv_tracking_url', esc_url_raw(wp_unslash($_POST['nv_tracking_url'] ?? '')));
	$order->update_meta_data('_nv_expected', $expected);
	if ($expected !== $old_expected) {
		$order->delete_meta_data('_nv_reminder_sent');
		// The "delivery date" email already says it's tomorrow, so don't send the reminder as well.
		if ($expected && $expected === wp_date('Y-m-d', time() + DAY_IN_SECONDS)) $order->update_meta_data('_nv_reminder_sent', $expected);
		if ($expected) nvc_log($order, sprintf(__('Expected delivery: %s', 'neemveda'), nvc_fmt_date($expected)));
	}
	if ($place) {
		$order->update_meta_data('_nv_place', $place);
		nvc_log($order, __('Location update', 'neemveda'), $place);
	}
	$order->save();

	// One message per save: a status change sends its own email (which includes date and tracking);
	// otherwise a new date or location update gets its own email.
	$new_status = isset($_POST['order_status']) ? str_replace('wc-', '', sanitize_text_field(wp_unslash($_POST['order_status']))) : $order->get_status();
	$status_changes = $new_status !== $order->get_status();
	$notify = !empty($_POST['nv_notify']);
	set_transient('nvc_notify_' . $order_id, $notify ? '1' : '0', 60);
	if ($notify && !$status_changes) {
		if ($expected && $expected !== $old_expected) nvc_notify_customer($order, 'expected');
		elseif ($place) nvc_notify_customer($order, 'location');
	}
}

/* ------------------------------------------------------------------ status changes */
add_action('woocommerce_order_status_changed', function ($order_id, $from, $to, $order) {
	nvc_log($order, nvc_status_label($to));
	$order->save();
	$notify = get_transient('nvc_notify_' . $order_id);
	if ($notify === '0') return; // owner unticked "Email the customer"
	if (in_array($to, ['packed', 'shipped', 'out-for-delivery'], true)) {
		nvc_notify_customer($order, $to);
	} elseif (in_array($to, ['processing', 'completed', 'cancelled'], true)) {
		// WooCommerce sends its own email for these; add the push notification.
		nvc_notify_push_only($order, $to === 'processing' ? 'placed' : ($to === 'completed' ? 'delivered' : 'cancelled'));
	}
}, 10, 4);

// WooCommerce's "completed" email reads as "delivered" here.
add_filter('woocommerce_email_subject_customer_completed_order', fn($s, $order) => sprintf(__('Delivered: your neemveda order #%s', 'neemveda'), $order ? $order->get_order_number() : ''), 10, 2);
add_filter('woocommerce_email_heading_customer_completed_order', fn() => __('Your order has been delivered', 'neemveda'));

// Tracking details inside WooCommerce's own emails to the customer.
add_action('woocommerce_email_order_details', function ($order, $sent_to_admin) {
	if ($sent_to_admin) return;
	$exp = $order->get_meta('_nv_expected');
	$est = nvc_estimate_text($order);
	$url = nvc_track_url($order);
	echo '<p style="margin:0 0 16px">';
	if ($exp && !$order->has_status(['completed', 'cancelled'])) echo '🚚 <strong>' . esc_html__('Expected delivery:', 'neemveda') . '</strong> ' . esc_html(nvc_fmt_date($exp)) . '<br>';
	elseif ($est && $order->has_status(['processing', 'on-hold'])) echo '🚚 <strong>' . esc_html__('Expected delivery:', 'neemveda') . '</strong> ' . esc_html($est) . '<br>';
	echo '<a href="' . esc_url($url) . '">' . esc_html__('Track your order', 'neemveda') . '</a></p>';
}, 5, 2);

/* ------------------------------------------------------------------ messages */
function nvc_track_url($order) {
	$page = get_page_by_path('track-order');
	$base = $page ? get_permalink($page) : home_url('/track-order/');
	return add_query_arg(['order' => $order->get_order_number()], $base);
}

function nvc_estimate_text($order) {
	$days = get_theme_mod('nv_delivery_days', '3–7');
	preg_match_all('/\d+/', (string) $days, $m);
	if (empty($m[0])) return '';
	$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
	$from = wp_date('D, j M', $created + (int) $m[0][0] * DAY_IN_SECONDS);
	$to = wp_date('D, j M', $created + (int) ($m[0][1] ?? $m[0][0]) * DAY_IN_SECONDS);
	return $from === $to ? $from : $from . ' – ' . $to;
}

/** Text for each tracking message: [email subject, heading, sentence]. */
function nvc_message($order, $kind) {
	$n = $order->get_order_number();
	$exp = $order->get_meta('_nv_expected') ? nvc_fmt_date($order->get_meta('_nv_expected')) : '';
	$track = trim($order->get_meta('_nv_courier') . ' ' . $order->get_meta('_nv_tracking_no'));
	$place = $order->get_meta('_nv_place');
	$cod = $order->get_payment_method() === 'cod' ? sprintf(__(' Please keep %s ready (Cash on Delivery).', 'neemveda'), wp_strip_all_tags(wc_price($order->get_total(), ['decimals' => 0]))) : '';
	switch ($kind) {
		case 'packed':
			return [sprintf(__('Your order #%s is packed', 'neemveda'), $n), __('Your order is packed', 'neemveda'),
				sprintf(__('Your neemveda order #%s is packed and will be handed to the courier soon.', 'neemveda'), $n) . ($exp ? ' ' . sprintf(__('Expected delivery: %s.', 'neemveda'), $exp) : '')];
		case 'shipped':
			return [sprintf(__('Your order #%s has shipped', 'neemveda'), $n), __('Your order is on its way', 'neemveda'),
				sprintf(__('Your neemveda order #%s has been shipped.', 'neemveda'), $n) . ($track ? ' ' . sprintf(__('Tracking: %s.', 'neemveda'), $track) : '') . ($exp ? ' ' . sprintf(__('Expected delivery: %s.', 'neemveda'), $exp) : '') . $cod];
		case 'out-for-delivery':
			return [sprintf(__('Out for delivery today: order #%s', 'neemveda'), $n), __('Your order arrives today', 'neemveda'),
				sprintf(__('Your neemveda order #%s is out for delivery and will reach you today.', 'neemveda'), $n) . $cod];
		case 'expected':
			return [sprintf(__('Delivery date for order #%s: %s', 'neemveda'), $n, $exp), sprintf(__('Arriving on %s', 'neemveda'), $exp),
				sprintf(__('Your neemveda order #%1$s is expected to be delivered on %2$s.', 'neemveda'), $n, $exp) . $cod];
		case 'reminder':
			return [sprintf(__('Arriving tomorrow: order #%s', 'neemveda'), $n), __('Your order arrives tomorrow', 'neemveda'),
				sprintf(__('Your neemveda order #%1$s is expected tomorrow, %2$s. Please make sure someone is available to receive it.', 'neemveda'), $n, $exp) . $cod];
		case 'location':
			return [sprintf(__('Update on order #%1$s: %2$s', 'neemveda'), $n, $place), __('Delivery update', 'neemveda'),
				sprintf(__('Update on your neemveda order #%1$s: %2$s.', 'neemveda'), $n, $place) . ($exp ? ' ' . sprintf(__('Expected delivery: %s.', 'neemveda'), $exp) : '')];
		case 'placed':
			return [sprintf(__('Order #%s confirmed', 'neemveda'), $n), __('Order confirmed', 'neemveda'), sprintf(__('Thank you! Your neemveda order #%s is confirmed.', 'neemveda'), $n)];
		case 'delivered':
			return [sprintf(__('Delivered: order #%s', 'neemveda'), $n), __('Delivered', 'neemveda'), sprintf(__('Your neemveda order #%s has been delivered. Thank you!', 'neemveda'), $n)];
		case 'cancelled':
			return [sprintf(__('Order #%s cancelled', 'neemveda'), $n), __('Order cancelled', 'neemveda'), sprintf(__('Your neemveda order #%s has been cancelled.', 'neemveda'), $n)];
	}
	return ['', '', ''];
}

function nvc_notify_customer($order, $kind) {
	[$subject, $heading, $line] = nvc_message($order, $kind);
	if (!$subject) return;
	$to = $order->get_billing_email();
	$url = nvc_track_url($order);
	$body = '<p>' . sprintf(esc_html__('Hi %s,', 'neemveda'), esc_html($order->get_billing_first_name())) . '</p><p>' . esc_html($line) . '</p>';
	if ($order->get_meta('_nv_tracking_url')) {
		$body .= '<p><a href="' . esc_url($order->get_meta('_nv_tracking_url')) . '">' . esc_html__('Track with the courier', 'neemveda') . '</a></p>';
	}
	$body .= '<p><a href="' . esc_url($url) . '" style="background:#2F4F2F;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:bold">' . esc_html__('Track your order', 'neemveda') . '</a></p>';
	$ok = $to ? nvc_send_wc_mail($to, $subject, $heading, $body) : false;
	$order->add_order_note(sprintf(__('neemveda: "%1$s" email %2$s.', 'neemveda'), $heading, $ok ? 'sent' : 'NOT sent'));
	nvc_push_order($order, $heading, $line, $url);
}

function nvc_notify_push_only($order, $kind) {
	[, $heading, $line] = nvc_message($order, $kind);
	if ($heading) nvc_push_order($order, $heading, $line, nvc_track_url($order));
}

/* ------------------------------------------------------------------ reminder the day before delivery */
add_action('nvc_hourly', 'nvc_send_reminders');
function nvc_send_reminders() {
	if ((int) wp_date('G') < 9) return; // from 9 am, site time zone
	$tomorrow = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
	$orders = wc_get_orders([
		'limit'      => 100,
		'status'     => ['processing', 'packed', 'shipped'],
		'meta_key'   => '_nv_expected',
		'meta_value' => $tomorrow,
	]);
	foreach ($orders as $order) {
		if ($order->get_meta('_nv_reminder_sent') === $tomorrow) continue;
		$order->update_meta_data('_nv_reminder_sent', $tomorrow);
		$order->save();
		nvc_notify_customer($order, 'reminder');
	}
}

/* ------------------------------------------------------------------ customer timeline */
function nvc_render_tracking($order) {
	$status = $order->get_status();
	$steps = nvc_track_steps();
	$keys = array_keys($steps);
	$current = array_search($status, $keys, true);
	if ($current === false) $current = in_array($status, ['pending', 'on-hold'], true) ? 0 : -1;
	ob_start();
	echo '<div class="nv-track-card">';
	echo '<p class="eyebrow">' . sprintf(esc_html__('Order #%s', 'neemveda'), esc_html($order->get_order_number())) . '</p>';
	if ($status === 'cancelled' || $status === 'refunded' || $status === 'failed') {
		echo '<h3>' . esc_html(nvc_status_label($status)) . '</h3>';
	} else {
		echo '<h3>' . esc_html($steps[$keys[max(0, $current)]] ?? nvc_status_label($status)) . '</h3>';
		echo '<ol class="nv-track-steps">';
		foreach ($keys as $i => $k) {
			$cls = $i <= $current ? 'is-done' : '';
			if ($i === $current) $cls .= ' is-current';
			echo '<li class="' . esc_attr(trim($cls)) . '"><strong>' . esc_html($steps[$k]) . '</strong></li>';
		}
		echo '</ol>';
	}
	$exp = $order->get_meta('_nv_expected');
	$meta = [];
	if ($status !== 'completed' && $status !== 'cancelled') {
		if ($exp) $meta[] = '🚚 <strong>' . esc_html__('Expected delivery:', 'neemveda') . '</strong> ' . esc_html(nvc_fmt_date($exp));
		elseif ($est = nvc_estimate_text($order)) $meta[] = '🚚 <strong>' . esc_html__('Estimated delivery:', 'neemveda') . '</strong> ' . esc_html($est);
		if ($order->get_meta('_nv_place')) $meta[] = '📍 <strong>' . esc_html__('Now at:', 'neemveda') . '</strong> ' . esc_html($order->get_meta('_nv_place'));
	}
	$track = trim($order->get_meta('_nv_courier') . ' ' . $order->get_meta('_nv_tracking_no'));
	if ($track) {
		$t = '📦 <strong>' . esc_html__('Tracking:', 'neemveda') . '</strong> ' . esc_html($track);
		if ($order->get_meta('_nv_tracking_url')) $t .= ' · <a href="' . esc_url($order->get_meta('_nv_tracking_url')) . '" target="_blank" rel="noopener">' . esc_html__('Track with courier', 'neemveda') . '</a>';
		$meta[] = $t;
	}
	if ($order->get_payment_method() === 'cod' && !in_array($status, ['completed', 'cancelled'], true)) {
		$meta[] = '💵 ' . sprintf(esc_html__('Cash on Delivery: please keep %s ready.', 'neemveda'), wp_kses_post(wc_price($order->get_total(), ['decimals' => 0])));
	}
	if ($meta) echo '<div class="nv-track-meta">' . implode('<br>', $meta) . '</div>';
	$log = $order->get_meta('_nv_track_log');
	if (is_array($log) && $log) {
		echo '<h4>' . esc_html__('Updates', 'neemveda') . '</h4><ul class="nv-track-log">';
		foreach (array_reverse($log) as $row) {
			echo '<li><strong>' . esc_html($row['label']) . '</strong>' . ($row['place'] ? ' · 📍 ' . esc_html($row['place']) : '') . ' <span class="muted">· ' . esc_html(wp_date('j M, g:i a', $row['at'])) . '</span></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
	return ob_get_clean();
}

// My account → Orders → View, and the thank-you page.
add_action('woocommerce_order_details_before_order_table', function ($order) {
	echo nvc_render_tracking($order); // phpcs:ignore WordPress.Security.EscapeOutput
}, 5);

// "Track" button in the order history list.
add_filter('woocommerce_my_account_my_orders_actions', function ($actions, $order) {
	$actions['nv-track'] = ['url' => $order->get_view_order_url(), 'name' => __('Track', 'neemveda')];
	return $actions;
}, 10, 2);

/* ------------------------------------------------------------------ [neemveda_track] */
add_shortcode('neemveda_track', function () {
	ob_start();
	$num = sanitize_text_field(wp_unslash($_REQUEST['order'] ?? ''));
	$who = sanitize_text_field(wp_unslash($_REQUEST['who'] ?? ''));
	$order = null;
	$error = '';
	if ($num !== '' && $who !== '') {
		if (!nvc_rate_ok('track|' . nvc_client_ip(), 30, HOUR_IN_SECONDS)) {
			$error = __('Too many lookups. Please try again later.', 'neemveda');
		} else {
			$candidate = wc_get_order(absint(preg_replace('/\D+/', '', $num)));
			$phone = function_exists('nvc_normalise_phone') ? nvc_normalise_phone($who) : '';
			if ($candidate instanceof WC_Order && (strcasecmp($candidate->get_billing_email(), $who) === 0 || ($phone && nvc_normalise_phone($candidate->get_billing_phone()) === $phone))) {
				$order = $candidate;
			} else {
				$error = __('We could not find an order with that number and email / mobile number.', 'neemveda');
			}
		}
	} elseif ($num !== '' && is_user_logged_in()) {
		$candidate = wc_get_order(absint(preg_replace('/\D+/', '', $num)));
		if ($candidate instanceof WC_Order && (int) $candidate->get_customer_id() === get_current_user_id()) $order = $candidate;
	}

	if (is_user_logged_in()) {
		$mine = wc_get_orders(['customer_id' => get_current_user_id(), 'limit' => 5]);
		if ($mine && !$order) {
			echo '<div class="nv-track-card"><h3>' . esc_html__('Your recent orders', 'neemveda') . '</h3><ul>';
			foreach ($mine as $o) {
				echo '<li><a href="' . esc_url(nvc_track_url($o)) . '">#' . esc_html($o->get_order_number()) . '</a> · ' . esc_html(wc_format_datetime($o->get_date_created())) . ' · ' . esc_html(nvc_status_label($o->get_status())) . '</li>';
			}
			echo '</ul><p><a href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">' . esc_html__('See all orders', 'neemveda') . '</a></p></div>';
		}
	}
	if ($order) {
		echo nvc_render_tracking($order); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if (!$order || $error) : ?>
		<form class="nv-track-form" method="get">
			<?php if ($error) : ?><p class="woocommerce-error" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?>
			<label for="nv_t_order"><?php esc_html_e('Order number', 'neemveda'); ?></label>
			<input class="input" id="nv_t_order" name="order" value="<?php echo esc_attr($num); ?>" placeholder="e.g. 1024" required>
			<label for="nv_t_who"><?php esc_html_e('Email or mobile number used for the order', 'neemveda'); ?></label>
			<input class="input" id="nv_t_who" name="who" value="<?php echo esc_attr($who); ?>" required>
			<button class="btn btn-primary" type="submit"><?php esc_html_e('Track order', 'neemveda'); ?></button>
		</form>
	<?php endif;
	return ob_get_clean();
});
