<?php defined('ABSPATH') || exit; ?>
<section class="newsletter section--dark" aria-labelledby="newsletter-title">
	<svg class="newsletter__leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
	<div class="container newsletter__inner reveal">
		<div>
			<p class="eyebrow eyebrow--light"><?php esc_html_e('Join the neemveda circle', 'neemveda'); ?></p>
			<h2 id="newsletter-title" class="newsletter__title"><?php esc_html_e('Simple rituals, delivered gently.', 'neemveda'); ?></h2>
			<p class="newsletter__text"><?php esc_html_e('Occasional notes on natural oral care, new packs and member-only offers. No spam, ever.', 'neemveda'); ?></p>
		</div>
		<form class="newsletter__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="nv_newsletter">
			<?php wp_nonce_field('nv_newsletter', 'nv_nonce'); ?>
			<label class="sr-only" for="nl-email"><?php esc_html_e('Email address', 'neemveda'); ?></label>
			<input id="nl-email" class="input input--on-dark" type="email" name="email" autocomplete="email" inputmode="email" placeholder="<?php esc_attr_e('Your email address', 'neemveda'); ?>" required>
			<button class="btn btn-light" type="submit"><?php esc_html_e('Subscribe', 'neemveda'); ?></button>
			<?php if (isset($_GET['nv_subscribed'])) : ?>
				<p class="form-msg is-success" role="status"><?php esc_html_e('Thank you! You are on the list.', 'neemveda'); ?></p>
			<?php endif; ?>
		</form>
	</div>
</section>
