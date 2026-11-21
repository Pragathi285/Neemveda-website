<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php get_template_part('parts/icons'); ?>
<a class="skip-link" href="#main"><?php esc_html_e('Skip to content', 'neemveda'); ?></a>
<?php
$nv_count = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;
$nv_featured = nv_featured_product();
?>
<header class="site-header" data-header>
	<div class="container site-header__inner">
		<button class="icon-btn nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
			<svg class="icon icon--open" aria-hidden="true"><use href="#i-menu"/></svg>
			<svg class="icon icon--close" aria-hidden="true"><use href="#i-close"/></svg>
			<span class="sr-only" data-nav-label><?php esc_html_e('Open menu', 'neemveda'); ?></span>
		</button>

		<a class="logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="neemveda, Natural Oral Care: home">
			<svg class="logo__mark" aria-hidden="true"><use href="#i-logo"/></svg>
			<span class="logo__text" aria-hidden="true">
				<span class="logo__word">neemveda</span>
				<span class="logo__tag">Natural Oral Care</span>
			</span>
		</a>

		<nav id="site-nav" class="site-nav" aria-label="Main" data-site-nav>
			<?php nv_menu('primary', nv_primary_fallback(), 'site-nav__list'); ?>
			<div class="site-nav__extra">
				<a class="btn btn-primary btn-block" href="<?php echo esc_url($nv_featured ? $nv_featured->get_permalink() : nv_shop_url()); ?>"><?php esc_html_e('Shop neem sticks', 'neemveda'); ?></a>
				<a class="btn btn-outline btn-block" href="<?php echo esc_url(nv_account_url()); ?>"><?php echo is_user_logged_in() ? esc_html__('My account & orders', 'neemveda') : esc_html__('Log in / Sign up', 'neemveda'); ?></a>
			</div>
		</nav>

		<div class="header-actions">
			<a class="icon-btn account-link" href="<?php echo esc_url(nv_account_url()); ?>" title="<?php echo is_user_logged_in() ? esc_attr__('My account', 'neemveda') : esc_attr__('Log in / Sign up', 'neemveda'); ?>">
				<svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>
				<span class="sr-only"><?php echo is_user_logged_in() ? esc_html__('My account', 'neemveda') : esc_html__('Log in or sign up', 'neemveda'); ?></span>
			</a>
			<a class="icon-btn cart-link" href="<?php echo esc_url(nv_cart_url()); ?>">
				<svg class="icon" aria-hidden="true"><use href="#i-bag"/></svg>
				<span class="sr-only"><?php esc_html_e('Cart', 'neemveda'); ?></span>
				<span class="cart-count<?php echo $nv_count ? ' has-items' : ''; ?>" aria-hidden="true"><?php echo (int) $nv_count; ?></span>
			</a>
		</div>
	</div>
</header>
<main id="main" tabindex="-1">
