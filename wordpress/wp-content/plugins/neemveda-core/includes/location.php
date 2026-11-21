<?php
/**
 * Delivery location pin. At checkout the customer drops a pin on a map (OpenStreetMap via Leaflet)
 * or taps "Use my current location". The coordinates are saved on the order and shown to the shop
 * owner on the order page and in the new-order email, with a Google Maps link for the courier.
 */
defined('ABSPATH') || exit;

const NVC_LEAFLET = 'https://unpkg.com/leaflet@1.9.4/dist/';

add_action('wp_enqueue_scripts', function () {
	if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) return;
	wp_enqueue_style('leaflet', NVC_LEAFLET . 'leaflet.css', [], '1.9.4');
	wp_enqueue_script('leaflet', NVC_LEAFLET . 'leaflet.js', [], '1.9.4', ['in_footer' => true]);
	wp_enqueue_script('nvc-map', NVC_URL . 'assets/map.js', ['leaflet'], NVC_VERSION, ['in_footer' => true]);
	[$lat, $lng] = array_map('floatval', array_pad(explode(',', (string) nvc_get('nv_map_center')), 2, 0));
	wp_localize_script('nvc-map', 'NVC_MAP', ['lat' => $lat ?: 11.0168, 'lng' => $lng ?: 76.9558]);
});

add_action('woocommerce_after_checkout_billing_form', function () {
	$lat = wc_clean(wp_unslash($_POST['nv_lat'] ?? ''));
	$lng = wc_clean(wp_unslash($_POST['nv_lng'] ?? ''));
	?>
	<div class="nv-map-wrap" id="nv-delivery-pin">
		<h3><?php esc_html_e('Pin your delivery location', 'neemveda'); ?> <span class="required" aria-hidden="true">*</span></h3>
		<p class="description"><?php esc_html_e('Tap the map to drop a pin at your door, or use your current location. Drag the pin to adjust. This helps the courier find you.', 'neemveda'); ?></p>
		<div class="nv-map-actions">
			<button type="button" class="button" data-nv-locate><?php esc_html_e('📍 Use my current location', 'neemveda'); ?></button>
			<span class="nv-map-status" data-nv-map-status role="status" aria-live="polite"></span>
		</div>
		<div class="nv-map" data-nv-map aria-label="<?php esc_attr_e('Map to pin your delivery location', 'neemveda'); ?>"></div>
		<input type="hidden" name="nv_lat" value="<?php echo esc_attr($lat); ?>" data-nv-lat>
		<input type="hidden" name="nv_lng" value="<?php echo esc_attr($lng); ?>" data-nv-lng>
	</div>
	<?php
}, 20);

function nvc_valid_coords($lat, $lng) {
	return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && ((float) $lat != 0 || (float) $lng != 0);
}

add_action('woocommerce_checkout_process', function () {
	if (!nvc_valid_coords($_POST['nv_lat'] ?? '', $_POST['nv_lng'] ?? '')) {
		wc_add_notice(__('Please pin your delivery location on the map.', 'neemveda'), 'error');
	}
});

add_action('woocommerce_checkout_create_order', function ($order) {
	$lat = $_POST['nv_lat'] ?? '';
	$lng = $_POST['nv_lng'] ?? '';
	if (nvc_valid_coords($lat, $lng)) {
		$order->update_meta_data('_nv_lat', round((float) $lat, 6));
		$order->update_meta_data('_nv_lng', round((float) $lng, 6));
	}
});

function nvc_order_map_link($order) {
	$lat = $order->get_meta('_nv_lat');
	$lng = $order->get_meta('_nv_lng');
	return ($lat !== '' && $lng !== '') ? 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng) : '';
}

/* Admin order page: map box. */
add_action('add_meta_boxes', function () {
	$screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
	add_meta_box('nv-delivery-pin', __('Delivery location pin', 'neemveda'), 'nvc_admin_map_box', $screen, 'side', 'high');
});

function nvc_admin_map_box($post_or_order) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
	if (!$order) return;
	$link = nvc_order_map_link($order);
	if (!$link) {
		echo '<p>' . esc_html__('No pin for this order.', 'neemveda') . '</p>';
		return;
	}
	$lat = (float) $order->get_meta('_nv_lat');
	$lng = (float) $order->get_meta('_nv_lng');
	$bbox = implode(',', [$lng - 0.004, $lat - 0.003, $lng + 0.004, $lat + 0.003]);
	echo '<iframe title="Delivery location" style="width:100%;height:220px;border:0;border-radius:6px" loading="lazy" src="' .
		esc_url('https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox) . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng)) . '"></iframe>';
	echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($link) . '">' . esc_html__('Open in Google Maps', 'neemveda') . '</a></p>';
	echo '<p class="description">' . esc_html($lat . ', ' . $lng) . '</p>';
}

/* New-order email (to the shop owner) and the customer's order page show the pin link. */
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin) {
	$link = nvc_order_map_link($order);
	if ($link && $sent_to_admin) {
		echo '<p><strong>' . esc_html__('Delivery pin:', 'neemveda') . '</strong> <a href="' . esc_url($link) . '">' . esc_html__('Open in Google Maps', 'neemveda') . '</a></p>';
	}
}, 10, 2);

add_action('woocommerce_order_details_after_customer_details', function ($order) {
	$link = nvc_order_map_link($order);
	if ($link) {
		echo '<p class="nv-pin-link"><a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html__('📍 Your delivery pin on the map', 'neemveda') . '</a></p>';
	}
});
