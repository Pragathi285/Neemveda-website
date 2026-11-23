<?php defined('ABSPATH') || exit; ?>
</main>
<?php
$nv_email = nv_opt('email');
$nv_featured = nv_featured_product();
?>
<footer class="site-footer">
	<svg class="site-footer__leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
	<div class="container footer-grid">
		<div class="footer-brand">
			<a class="logo logo--reverse" href="<?php echo esc_url(home_url('/')); ?>" aria-label="neemveda, Natural Oral Care: home">
				<svg class="logo__mark" aria-hidden="true"><use href="#i-logo"/></svg>
				<span class="logo__text" aria-hidden="true">
					<span class="logo__word">neemveda</span>
					<span class="logo__tag">Natural Oral Care</span>
				</span>
			</a>
			<p><?php esc_html_e('Bringing back the timeless wisdom of traditional Indian oral care, with the natural goodness of neem.', 'neemveda'); ?></p>
			<ul class="social" aria-label="<?php esc_attr_e('Social media', 'neemveda'); ?>">
				<?php foreach (nv_socials() as [$label, $url, $icon]) : ?>
					<li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr($label . ' (opens in a new tab)'); ?>"><svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr($icon); ?>"/></svg></a></li>
				<?php endforeach; ?>
				<?php if ($nv_email) : ?>
					<li><a href="mailto:<?php echo esc_attr($nv_email); ?>" aria-label="<?php esc_attr_e('Email us', 'neemveda'); ?>"><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg></a></li>
				<?php endif; ?>
			</ul>
		</div>

		<nav class="footer-col" aria-labelledby="f-shop">
			<h2 class="footer-title" id="f-shop"><?php esc_html_e('Shop', 'neemveda'); ?></h2>
			<?php nv_menu('footer_shop', [
				[$nv_featured ? $nv_featured->get_name() : 'Neem sticks', $nv_featured ? $nv_featured->get_permalink() : nv_shop_url()],
				['All products', nv_shop_url()],
				['Why neem', home_url('/#why-neem')],
				['How to use', home_url('/#how-to-use')],
				['Our story', nv_page_url('about', '/about/')],
			]); ?>
		</nav>

		<nav class="footer-col" aria-labelledby="f-help">
			<h2 class="footer-title" id="f-help"><?php esc_html_e('Help', 'neemveda'); ?></h2>
			<?php nv_menu('footer_help', [
				['My account & orders', nv_account_url()],
				['Track your order', nv_page_url('track-order', '/track-order/')],
				['Shipping policy', nv_page_url('shipping-policy', '/shipping-policy/')],
				['Refund policy', nv_page_url('refund-policy', '/refund-policy/')],
				['Privacy policy', nv_page_url('privacy-policy', '/privacy-policy/')],
				['Terms & conditions', nv_page_url('terms', '/terms/')],
				['Photo credits', nv_page_url('credits', '/credits/')],
				['Contact us', nv_page_url('contact', '/contact/')],
			]); ?>
		</nav>

		<div class="footer-col">
			<h2 class="footer-title"><?php esc_html_e('Get in touch', 'neemveda'); ?></h2>
			<ul class="footer-contact">
				<?php if ($nv_email) : ?>
					<li><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg><a href="mailto:<?php echo esc_attr($nv_email); ?>"><?php echo esc_html($nv_email); ?></a></li>
				<?php endif; ?>
				<?php if (nv_opt('instagram')) : ?>
					<li><svg class="icon" aria-hidden="true"><use href="#i-instagram"/></svg><a href="<?php echo esc_url(nv_opt('instagram')); ?>" target="_blank" rel="noopener"><?php echo esc_html(nv_opt('instagram_handle') ?: 'Instagram'); ?></a></li>
				<?php endif; ?>
				<?php if (nv_opt('youtube')) : ?>
					<li><svg class="icon" aria-hidden="true"><use href="#i-youtube"/></svg><a href="<?php echo esc_url(nv_opt('youtube')); ?>" target="_blank" rel="noopener">YouTube: <?php echo esc_html(nv_opt('youtube_name') ?: 'neemveda'); ?></a></li>
				<?php endif; ?>
				<?php foreach (['facebook', 'twitter', 'linkedin'] as $k) : $s = nv_socials()[$k] ?? null; if (!$s) continue; ?>
					<li><svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr($s[2]); ?>"/></svg><a href="<?php echo esc_url($s[1]); ?>" target="_blank" rel="noopener"><?php echo esc_html($s[0]); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<div class="container footer-bottom">
		<p>&copy; <?php echo esc_html(wp_date('Y')); ?> neemveda. <?php esc_html_e('All rights reserved.', 'neemveda'); ?></p>
		<p class="footer-note"><?php esc_html_e('Neem sticks support everyday oral hygiene. They are not a substitute for professional dental care; please keep up regular dental check-ups.', 'neemveda'); ?></p>
	</div>
</footer>
<div class="toast" role="status" aria-live="polite" data-toast></div>
<?php wp_footer(); ?>
</body>
</html>
