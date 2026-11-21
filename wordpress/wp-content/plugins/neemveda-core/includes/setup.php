<?php
/**
 * One-time setup:  wp neemveda setup [--admin-email=<email>]
 *
 * Safe to run again: it updates what exists instead of duplicating it. It sets up WooCommerce for India
 * (₹, no decimals), Cash on Delivery, delivery charges (₹50, free from ₹499), accounts (signup on
 * My account, login needed to order), the site pages, the 3 neem stick packs and the home page.
 */
defined('ABSPATH') || exit;

class NVC_Setup_Command {

	/**
	 * Set up the neemveda shop.
	 *
	 * [--admin-email=<email>]
	 * : Where new-order alerts go (default: the site admin email).
	 *
	 * [--skip-products]
	 * : Leave products alone.
	 */
	public function setup($args, $assoc) {
		$this->site_options($assoc['admin-email'] ?? '');
		$this->woocommerce();
		$this->payments_and_shipping();
		$pages = $this->pages();
		if (empty($assoc['skip-products'])) $this->products();
		$this->front_page($pages);
		flush_rewrite_rules();
		WP_CLI::success('neemveda shop is set up.');
	}

	private function site_options($alert_email) {
		update_option('blogname', 'neemveda');
		update_option('blogdescription', 'Natural Oral Care');
		update_option('timezone_string', 'Asia/Kolkata');
		update_option('date_format', 'j M Y');
		update_option('permalink_structure', '/%postname%/');
		update_option('users_can_register', 0); // customers sign up through WooCommerce (with OTP)
		update_option('default_comment_status', 'closed');
		update_option('nv_announce_on', '1');
		update_option('nv_free_shipping_min', '499');
		if ($alert_email) update_option('nv_alert_email', sanitize_email($alert_email));
		foreach (['hello-world' => 'post', 'sample-page' => 'page'] as $slug => $type) {
			$p = get_page_by_path($slug, OBJECT, $type);
			if ($p) wp_delete_post($p->ID, true);
		}
		WP_CLI::log('Site options set.');
	}

	private function woocommerce() {
		$opts = [
			'woocommerce_default_country'                      => 'IN:TN',
			'woocommerce_currency'                             => 'INR',
			'woocommerce_currency_pos'                         => 'left',
			'woocommerce_price_num_decimals'                   => '0',
			'woocommerce_price_thousand_sep'                   => ',',
			'woocommerce_allowed_countries'                    => 'specific',
			'woocommerce_specific_allowed_countries'           => ['IN'],
			'woocommerce_ship_to_countries'                    => '',
			'woocommerce_calc_taxes'                           => 'no',
			'woocommerce_enable_guest_checkout'                => 'no',
			'woocommerce_enable_checkout_login_reminder'       => 'yes',
			'woocommerce_enable_signup_and_login_from_checkout' => 'no',
			'woocommerce_enable_myaccount_registration'        => 'yes',
			'woocommerce_registration_generate_username'       => 'yes',
			'woocommerce_registration_generate_password'       => 'no',
			'woocommerce_enable_reviews'                       => 'yes',
			'woocommerce_coming_soon'                          => 'no',
			'woocommerce_store_pages_only'                     => 'no',
			'woocommerce_manage_stock'                         => 'no',
			'woocommerce_checkout_phone_field'                 => 'required',
			'woocommerce_checkout_company_field'               => 'hidden',
			'woocommerce_checkout_address_2_field'             => 'optional',
			'woocommerce_onboarding_profile'                   => ['completed' => true, 'skipped' => true],
			'woocommerce_task_list_hidden'                     => 'yes',
			'woocommerce_show_marketplace_suggestions'         => 'no',
			'woocommerce_allow_tracking'                       => 'no',
		];
		foreach ($opts as $k => $v) update_option($k, $v);
		WP_CLI::log('WooCommerce configured for India.');
	}

	private function payments_and_shipping() {
		update_option('woocommerce_cod_settings', [
			'enabled'            => 'yes',
			'title'              => 'Cash on Delivery',
			'description'        => 'Pay in cash when your order arrives. No extra charge.',
			'instructions'       => 'Please keep the exact amount ready when the courier arrives.',
			'enable_for_methods' => [],
			'enable_for_virtual' => 'yes',
		]);
		foreach (['bacs', 'cheque', 'paypal'] as $g) {
			$s = get_option("woocommerce_{$g}_settings", []);
			$s['enabled'] = 'no';
			update_option("woocommerce_{$g}_settings", $s);
		}

		$zone = null;
		foreach (WC_Shipping_Zones::get_zones() as $z) {
			if ($z['zone_name'] === 'India') $zone = new WC_Shipping_Zone($z['id']);
		}
		if (!$zone) {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name('India');
			$zone->add_location('IN', 'country');
			$zone->save();
		}
		$have = [];
		foreach ($zone->get_shipping_methods() as $m) $have[$m->id] = $m;
		if (!isset($have['flat_rate'])) $zone->add_shipping_method('flat_rate');
		if (!isset($have['free_shipping'])) $zone->add_shipping_method('free_shipping');
		foreach ($zone->get_shipping_methods() as $m) {
			$key = 'woocommerce_' . $m->id . '_' . $m->instance_id . '_settings';
			if ($m->id === 'flat_rate') {
				update_option($key, ['title' => 'Delivery', 'tax_status' => 'none', 'cost' => '50']);
			} elseif ($m->id === 'free_shipping') {
				update_option($key, ['title' => 'Free delivery', 'requires' => 'min_amount', 'min_amount' => '499', 'ignore_discounts' => 'no']);
			}
		}
		WC_Cache_Helper::get_transient_version('shipping', true);
		WP_CLI::log('Cash on Delivery on; delivery ₹50, free from ₹499.');
	}

	private function upsert_page($slug, $title, $content, $excerpt = '') {
		$existing = get_page_by_path($slug);
		$data = ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt];
		if ($existing) {
			$data['ID'] = $existing->ID;
			return wp_update_post(wp_slash($data));
		}
		return wp_insert_post(wp_slash($data));
	}

	private function pages() {
		$dir = NVC_DIR . 'setup-content/';
		$meta = json_decode(file_get_contents($dir . 'pages.json'), true);
		$theme = get_template_directory_uri();
		$ids = [];
		foreach ($meta as $slug => $info) {
			$html = str_replace('{{THEME}}', $theme, file_get_contents($dir . $slug . '.html'));
			$ids[$slug] = $this->upsert_page($slug, $info['title'], "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->", $info['excerpt']);
		}
		// WooCommerce pages with the classic shortcodes (OTP, map pin and tracking hook into these).
		$woo = [
			'shop'       => ['Shop', '', 'woocommerce_shop_page_id'],
			'cart'       => ['Cart', '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->', 'woocommerce_cart_page_id'],
			'checkout'   => ['Checkout', '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->', 'woocommerce_checkout_page_id'],
			'my-account' => ['My account', '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->', 'woocommerce_myaccount_page_id'],
		];
		foreach ($woo as $slug => [$title, $content, $option]) {
			$id = $this->upsert_page($slug, $title, $content);
			update_option($option, $id);
			$ids[$slug] = $id;
		}
		$ids['home'] = $this->upsert_page('home', 'Home', '<!-- The home page layout is in the neemveda theme (front-page.php). -->');
		update_option('wp_page_for_privacy_policy', $ids['privacy-policy']);
		update_option('woocommerce_terms_page_id', $ids['refund-policy']);
		WP_CLI::log('Pages created: ' . implode(', ', array_keys($ids)));
		return $ids;
	}

	private function front_page($pages) {
		update_option('show_on_front', 'page');
		update_option('page_on_front', $pages['home']);
	}

	private function image($file, $title) {
		$path = get_template_directory() . '/assets/img/' . $file;
		if (!file_exists($path)) return 0;
		$existing = get_posts(['post_type' => 'attachment', 'meta_key' => '_nv_source', 'meta_value' => $file, 'numberposts' => 1, 'fields' => 'ids']);
		if ($existing) return $existing[0];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam($file);
		copy($path, $tmp);
		$id = media_handle_sideload(['name' => $file, 'tmp_name' => $tmp], 0, $title);
		if (is_wp_error($id)) {
			WP_CLI::warning('Image ' . $file . ': ' . $id->get_error_message());
			return 0;
		}
		update_post_meta($id, '_nv_source', $file);
		update_post_meta($id, '_wp_attachment_image_alt', $title);
		return $id;
	}

	private function products() {
		$term = term_exists('Neem sticks', 'product_cat') ?: wp_insert_term('Neem sticks', 'product_cat', ['slug' => 'neem-sticks']);
		$cat = is_array($term) ? (int) $term['term_id'] : (int) $term;
		$gallery = array_filter([
			$this->image('natural/neem-leaves-closeup-sq.jpg', 'Fresh neem leaves'),
			$this->image('natural/neem-tree-sq.jpg', 'A neem tree'),
			$this->image('natural/product-sticks-pile.jpg', 'A pile of neem sticks with leaves'),
		]);
		$packs = [
			['neem-sticks-pack-of-10', 'Neem Sticks – Pack of 10', 10, 149, 99, false, 'Starter pack', 'natural/product-sticks-leaves.jpg', 'Try natural neem datun. A good first pack.'],
			['neem-sticks-pack-of-30', 'Neem Sticks – Pack of 30', 30, 399, 249, true, 'Bestseller', 'natural/product-bundle-leaf.jpg', 'A month of plastic-free brushing.'],
			['neem-sticks-pack-of-60', 'Neem Sticks – Pack of 60', 60, 699, 449, false, 'Family pack', 'natural/product-sticks-pile.jpg', 'Family pack for the whole household.'],
		];
		foreach ($packs as $i => [$slug, $name, $count, $mrp, $price, $featured, $badge, $img, $short]) {
			$found = get_page_by_path($slug, OBJECT, 'product');
			$p = $found ? wc_get_product($found->ID) : new WC_Product_Simple();
			$p->set_name($name);
			$p->set_slug($slug);
			$p->set_status('publish');
			$p->set_catalog_visibility('visible');
			$p->set_regular_price((string) $mrp);
			$p->set_sale_price((string) $price);
			$p->set_featured($featured);
			$p->set_menu_order($i);
			$p->set_short_description($short . ' Hand-cut neem twigs (datun), tied with jute twine in a recyclable paper box.');
			$p->set_description("<p>neemveda Natural Neem Sticks are hand-cut twigs of the neem tree, the traditional Indian toothbrush (datun). Chew one end until it softens into bristles, brush gently, then rinse.</p>\n<ul><li>{$count} natural neem sticks</li><li>Plastic-free: jute twine and a recyclable paper box</li><li>No chemicals, no plastic bristles</li><li>Each stick lasts a few days: trim the used end and chew a fresh one</li></ul>");
			$p->set_category_ids([$cat]);
			$p->set_stock_status('instock');
			$p->set_reviews_allowed(true);
			$main = $this->image($img, $name);
			if ($main) $p->set_image_id($main);
			$p->set_gallery_image_ids(array_values(array_diff($gallery, [$main])));
			$p->update_meta_data('nv_count', $count);
			$p->update_meta_data('nv_badge', $badge);
			$p->save();
			WP_CLI::log("Product: {$name} (₹{$price}, MRP ₹{$mrp})");
		}
	}
}

WP_CLI::add_command('neemveda', 'NVC_Setup_Command');
