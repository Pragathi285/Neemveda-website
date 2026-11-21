<?php
/**
 * neemveda theme.
 *
 * Contact details and social links are edited in Appearance → Customize → "neemveda: contact & social".
 * Shop logic (cart, checkout, Cash on Delivery, accounts, order history) is WooCommerce.
 * OTP, delivery map pin, order tracking, alerts and the announcement bar are in the neemveda-core plugin.
 */

defined('ABSPATH') || exit;

const NV_THEME_VERSION = '1.0.0';

/* ------------------------------------------------------------------ setup */
add_action('after_setup_theme', function () {
	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('html5', ['search-form', 'comment-form', 'gallery', 'caption', 'style', 'script']);
	add_theme_support('woocommerce', [
		'thumbnail_image_width' => 600,
		'single_image_width'    => 900,
		'product_grid'          => ['default_columns' => 3, 'default_rows' => 4],
	]);
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');
	register_nav_menus([
		'primary'     => __('Main menu', 'neemveda'),
		'footer_shop' => __('Footer: Shop', 'neemveda'),
		'footer_help' => __('Footer: Help', 'neemveda'),
	]);
});

/* ------------------------------------------------------------------ assets */
function nv_asset($path) {
	return get_template_directory_uri() . '/assets/' . ltrim($path, '/');
}

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('nv-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght,SOFT@0,9..144,400..700,0..100;1,9..144,400..600,0..100&family=Source+Sans+3:wght@400;600;700&family=Nunito+Sans:wght@800&display=swap', [], null);
	wp_enqueue_style('nv-styles', nv_asset('css/styles.css'), [], NV_THEME_VERSION);
	$deps = ['nv-styles'];
	if (class_exists('WooCommerce')) {
		$deps[] = 'woocommerce-general';
	}
	wp_enqueue_style('nv-woo', nv_asset('css/woo.css'), $deps, NV_THEME_VERSION);
	wp_enqueue_script('nv-theme', nv_asset('js/theme.js'), [], NV_THEME_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
});

add_action('wp_head', function () {
	echo '<script>document.documentElement.classList.add("js")</script>' . "\n";
	echo '<meta name="theme-color" content="#2F4F2F">' . "\n";
	echo '<link rel="icon" href="' . esc_url(nv_asset('img/favicon.svg')) . '" type="image/svg+xml">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . esc_url(nv_asset('img/apple-touch-icon.png')) . '">' . "\n";
}, 2);

/* ------------------------------------------------------------------ contact & social settings */
function nv_defaults() {
	return [
		'email'            => 'neemveda0@gmail.com',
		'instagram'        => 'https://www.instagram.com/neemveda_official/',
		'instagram_handle' => '@neemveda_official',
		'youtube'          => 'https://www.youtube.com/@Neemveda',
		'youtube_name'     => '@Neemveda',
		'facebook'         => '',
		'twitter'          => '',
		'linkedin'         => '',
		'address'          => '',
		'hours'            => 'Mon–Sat, 10am–6pm',
		'dispatch_days'    => '1–2',
		'delivery_days'    => '3–7',
	];
}

function nv_opt($key) {
	$d = nv_defaults();
	return get_theme_mod('nv_' . $key, $d[$key] ?? '');
}

add_action('customize_register', function ($wp) {
	$wp->add_section('nv_contact', ['title' => __('neemveda: contact & social', 'neemveda'), 'priority' => 30]);
	$fields = [
		'email'            => ['Email shown on the website', 'email'],
		'instagram'        => ['Instagram link', 'url'],
		'instagram_handle' => ['Instagram handle (e.g. @neemveda_official)', 'text'],
		'youtube'          => ['YouTube link', 'url'],
		'youtube_name'     => ['YouTube name', 'text'],
		'facebook'         => ['Facebook link (optional)', 'url'],
		'twitter'          => ['X / Twitter link (optional)', 'url'],
		'linkedin'         => ['LinkedIn link (optional)', 'url'],
		'address'          => ['Business address (optional)', 'text'],
		'hours'            => ['Business hours', 'text'],
		'dispatch_days'    => ['Dispatch time in working days (e.g. 1–2)', 'text'],
		'delivery_days'    => ['Delivery time in days (e.g. 3–7)', 'text'],
	];
	foreach ($fields as $key => [$label, $type]) {
		$wp->add_setting('nv_' . $key, [
			'default'           => nv_defaults()[$key],
			'sanitize_callback' => $type === 'url' ? 'esc_url_raw' : ($type === 'email' ? 'sanitize_email' : 'sanitize_text_field'),
		]);
		$wp->add_control('nv_' . $key, ['label' => $label, 'section' => 'nv_contact', 'type' => $type === 'url' ? 'url' : ($type === 'email' ? 'email' : 'text')]);
	}
});

/** Social links that have a URL, as [key => [label, url, icon]]. */
function nv_socials() {
	$all = [
		'instagram' => ['Instagram', nv_opt('instagram'), 'i-instagram'],
		'youtube'   => ['YouTube', nv_opt('youtube'), 'i-youtube'],
		'facebook'  => ['Facebook', nv_opt('facebook'), 'i-facebook'],
		'twitter'   => ['X', nv_opt('twitter'), 'i-xlogo'],
		'linkedin'  => ['LinkedIn', nv_opt('linkedin'), 'i-linkedin'],
	];
	return array_filter($all, fn($s) => !empty($s[1]));
}

/* ------------------------------------------------------------------ links used by templates */
function nv_page_url($slug, $fallback = '/') {
	$page = get_page_by_path($slug);
	return $page ? get_permalink($page) : home_url($fallback);
}
function nv_shop_url() {
	return function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
}
function nv_cart_url() {
	return function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
}
function nv_account_url() {
	return function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
}

/** The featured product (WooCommerce "featured" star), else the first product. */
function nv_featured_product() {
	if (!function_exists('wc_get_products')) return null;
	$p = wc_get_products(['featured' => true, 'limit' => 1, 'status' => 'publish']);
	if (!$p) $p = wc_get_products(['limit' => 1, 'status' => 'publish', 'orderby' => 'menu_order', 'order' => 'ASC']);
	return $p ? $p[0] : null;
}

/** "₹499" style text for the free-shipping threshold (from neemveda-core settings, default 499). */
function nv_free_shipping_text() {
	$amount = (int) get_option('nv_free_shipping_min', 499);
	return function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount, ['decimals' => 0])) : '₹' . $amount;
}

/* ------------------------------------------------------------------ menus with sensible fallbacks */
function nv_menu($location, $fallback_items, $class = '') {
	if (has_nav_menu($location)) {
		wp_nav_menu(['theme_location' => $location, 'container' => false, 'menu_class' => $class, 'depth' => 1]);
		return;
	}
	echo '<ul class="' . esc_attr($class) . '">';
	foreach ($fallback_items as [$label, $url]) {
		echo '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
	}
	echo '</ul>';
}

function nv_primary_fallback() {
	return [
		['Home', home_url('/')],
		['Shop', nv_shop_url()],
		['Why neem', home_url('/#why-neem')],
		['How to use', home_url('/#how-to-use')],
		['About', nv_page_url('about', '/about/')],
		['Contact', nv_page_url('contact', '/contact/')],
	];
}

/* ------------------------------------------------------------------ WooCommerce tweaks */
// Live cart count in the header after "Add to cart".
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
	$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$fragments['span.cart-count'] = '<span class="cart-count' . ($count ? ' has-items' : '') . '" aria-hidden="true">' . (int) $count . '</span>';
	return $fragments;
});

// woocommerce.php provides the container and the page title.
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
add_filter('woocommerce_show_page_title', '__return_false');

// "Save 38%" instead of "Sale!".
add_filter('woocommerce_sale_flash', function ($html, $post, $product) {
	$regular = (float) $product->get_regular_price();
	$sale = (float) $product->get_sale_price();
	if ($regular > 0 && $sale > 0 && $sale < $regular) {
		return '<span class="onsale">' . sprintf(esc_html__('Save %d%%', 'neemveda'), round(100 - $sale / $regular * 100)) . '</span>';
	}
	return $html;
}, 10, 3);

// Breadcrumb styling hook.
add_filter('woocommerce_breadcrumb_defaults', function ($d) {
	$d['wrap_before'] = '<nav class="breadcrumbs woocommerce-breadcrumb" aria-label="Breadcrumb">';
	$d['wrap_after']  = '</nav>';
	$d['delimiter']   = ' <span aria-hidden="true">/</span> ';
	return $d;
});

/* ------------------------------------------------------------------ misc */
add_filter('body_class', function ($classes) {
	if (is_front_page()) $classes[] = 'page-home';
	return $classes;
});

// Hide the admin bar for customers on the front end.
add_filter('show_admin_bar', fn($show) => current_user_can('edit_posts') ? $show : false);
