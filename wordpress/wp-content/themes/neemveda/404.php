<?php
defined('ABSPATH') || exit;
get_header(); ?>
<section class="section">
	<div class="container center" style="max-width:640px">
		<p class="eyebrow"><?php esc_html_e('Page not found', 'neemveda'); ?></p>
		<h1><?php esc_html_e('This twig has wandered off', 'neemveda'); ?></h1>
		<p class="muted"><?php esc_html_e('The page you were looking for does not exist or has moved.', 'neemveda'); ?></p>
		<div class="btn-row" style="justify-content:center;margin-top:20px">
			<a class="btn btn-primary" href="<?php echo esc_url(nv_shop_url()); ?>"><?php esc_html_e('Shop neem sticks', 'neemveda'); ?></a>
			<a class="btn btn-outline" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Go home', 'neemveda'); ?></a>
		</div>
	</div>
</section>
<?php get_footer();
