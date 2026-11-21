<?php
/**
 * Standard page: a page header with the title (and the page excerpt as a subtitle),
 * then the content. Content that starts with its own <section> blocks (e.g. About) is shown full width;
 * anything else is placed in a readable column.
 */
defined('ABSPATH') || exit;
get_header();

while (have_posts()) :
	the_post();
	$nv_content = get_the_content();
	$nv_is_woo = function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page());
	// Content with its own <section> blocks or its own container (e.g. About, Contact) is shown full width.
	$nv_full = (bool) preg_match('/^\s*(<!--.*?-->\s*)*<(section|div class="container)/s', $nv_content);
	?>
	<section class="page-hero<?php echo $nv_is_woo ? ' page-hero--compact' : ''; ?>" aria-labelledby="page-title">
		<svg class="deco-leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
		<div class="container">
			<?php if (!$nv_is_woo) : ?>
				<nav class="breadcrumbs" aria-label="Breadcrumb"><ol style="justify-content:center"><li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li><li aria-current="page"><?php the_title(); ?></li></ol></nav>
			<?php endif; ?>
			<h1 id="page-title"><?php the_title(); ?></h1>
			<?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
		</div>
	</section>
	<?php if ($nv_full) : ?>
		<?php the_content(); ?>
	<?php else : ?>
		<div class="container <?php echo $nv_is_woo ? 'woo-wrap' : 'prose page-body'; ?>">
			<?php the_content(); ?>
		</div>
	<?php endif; ?>
<?php endwhile;

get_footer();
