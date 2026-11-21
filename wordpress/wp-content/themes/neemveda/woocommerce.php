<?php
/**
 * Wrapper for WooCommerce shop and product pages.
 */
defined('ABSPATH') || exit;
get_header();
if (is_shop() || is_product_taxonomy()) : ?>
	<section class="page-hero" aria-labelledby="shop-title">
		<svg class="deco-leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
		<div class="container">
			<p class="eyebrow"><?php esc_html_e('Plastic-free oral care', 'neemveda'); ?></p>
			<h1 id="shop-title"><?php woocommerce_page_title(); ?></h1>
			<p><?php esc_html_e('Hand-cut neem sticks (datun) in recyclable paper boxes. Pay by Cash on Delivery.', 'neemveda'); ?></p>
		</div>
	</section>
<?php endif; ?>
<div class="container woo-wrap <?php echo is_product() ? 'woo-product' : 'woo-shop'; ?>">
	<?php
	if (is_product()) woocommerce_breadcrumb();
	woocommerce_content();
	?>
</div>
<?php get_footer();
