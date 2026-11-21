<?php
defined('ABSPATH') || exit;
get_header(); ?>
<section class="page-hero">
	<div class="container"><h1><?php echo is_search() ? esc_html__('Search results', 'neemveda') : esc_html(get_the_archive_title() ?: get_bloginfo('name')); ?></h1></div>
</section>
<div class="container prose page-body">
	<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
		<article <?php post_class('card'); ?> style="padding:20px;margin-bottom:16px">
			<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
			<?php the_excerpt(); ?>
		</article>
	<?php endwhile; the_posts_pagination(); else : ?>
		<p><?php esc_html_e('Nothing found.', 'neemveda'); ?></p>
	<?php endif; ?>
</div>
<?php get_footer();
