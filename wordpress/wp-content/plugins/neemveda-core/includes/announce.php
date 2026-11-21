<?php
/**
 * Announcement bar at the very top of every page. Turn it on and edit the text in WooCommerce → neemveda settings.
 * Visitors can close it; it comes back when the text changes.
 */
defined('ABSPATH') || exit;

add_action('wp_body_open', function () {
	if (!nvc_get('nv_announce_on')) return;
	$text = nvc_get('nv_announce_text');
	if (!$text) return;
	$link = nvc_get('nv_announce_link');
	$id = substr(md5($text . $link), 0, 8);
	?>
	<div class="nv-announce" data-nv-announce="<?php echo esc_attr($id); ?>">
		<span><?php echo esc_html($text); ?></span>
		<?php if ($link) : ?> · <a href="<?php echo esc_url($link); ?>"><?php echo esc_html(nvc_get('nv_announce_link_text') ?: __('Learn more', 'neemveda')); ?></a><?php endif; ?>
		<button type="button" class="nv-announce__close" aria-label="<?php esc_attr_e('Close announcement', 'neemveda'); ?>">×</button>
	</div>
	<script>
	(function () {
		var bar = document.querySelector('[data-nv-announce]'); if (!bar) return;
		var key = 'nvAnnounce-' + bar.getAttribute('data-nv-announce');
		try { if (localStorage.getItem(key)) { bar.remove(); return; } } catch (e) {}
		bar.querySelector('button').addEventListener('click', function () { bar.remove(); try { localStorage.setItem(key, '1'); } catch (e) {} });
	})();
	</script>
	<?php
}, 1);
