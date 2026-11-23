<?php
/**
 * Browser push notifications with OneSignal (free plan).
 * Once the App ID and REST API key are saved in neemveda settings:
 *   - visitors see a small "Get order updates" prompt and can allow notifications;
 *   - logged-in customers are linked to their account, so each order update is pushed to them;
 *   - offers and announcements can be sent to everyone from the OneSignal dashboard.
 */
defined('ABSPATH') || exit;

function nvc_push_ready() {
	return (bool) nvc_get('nv_onesignal_app_id');
}

add_action('wp_head', function () {
	if (!nvc_push_ready() || is_admin()) return;
	$app = nvc_get('nv_onesignal_app_id');
	$uid = is_user_logged_in() ? 'nv-' . get_current_user_id() : '';
	?>
	<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
	<script>
	window.OneSignalDeferred = window.OneSignalDeferred || [];
	OneSignalDeferred.push(async function (OneSignal) {
		await OneSignal.init({ appId: <?php echo wp_json_encode($app); ?>, serviceWorkerParam: { scope: '/' }, serviceWorkerPath: 'OneSignalSDKWorker.js', promptOptions: { slidedown: { prompts: [] } } });
		<?php if ($uid) : ?>try { await OneSignal.login(<?php echo wp_json_encode($uid); ?>); } catch (e) {}<?php endif; ?>
		var box = document.querySelector('[data-nv-push]');
		if (!box || !OneSignal.Notifications.isPushSupported() || OneSignal.Notifications.permission) return;
		try { if (localStorage.getItem('nvPushDismissed')) return; } catch (e) {}
		// Wait until any popup (like the welcome "Create your profile" box) is closed, so prompts never stack.
		function nvShowPush() {
			if (document.querySelector('dialog[open]')) { setTimeout(nvShowPush, 4000); return; }
			box.classList.add('is-visible');
		}
		setTimeout(nvShowPush, 8000);
		box.querySelector('[data-nv-push-yes]').addEventListener('click', function () { box.classList.remove('is-visible'); OneSignal.Notifications.requestPermission(); });
		box.querySelector('[data-nv-push-no]').addEventListener('click', function () { box.classList.remove('is-visible'); try { localStorage.setItem('nvPushDismissed', '1'); } catch (e) {} });
	});
	</script>
	<?php
});

add_action('wp_footer', function () {
	if (!nvc_push_ready()) return;
	?>
	<div class="nv-push-prompt" data-nv-push role="dialog" aria-label="<?php esc_attr_e('Notifications', 'neemveda'); ?>">
		<p><strong>🔔 <?php esc_html_e('Get order updates and offers?', 'neemveda'); ?></strong><br><?php esc_html_e('We will notify you when your order ships and arrives. You can turn this off anytime.', 'neemveda'); ?></p>
		<div class="btn-row"><button type="button" class="btn btn-primary" data-nv-push-yes><?php esc_html_e('Allow', 'neemveda'); ?></button><button type="button" class="btn btn-ghost" data-nv-push-no><?php esc_html_e('Not now', 'neemveda'); ?></button></div>
	</div>
	<?php
});

// OneSignal's service worker must be served from the site root.
add_action('init', function () {
	$path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
	if ($path === '/OneSignalSDKWorker.js' && nvc_push_ready()) {
		header('Content-Type: application/javascript; charset=utf-8');
		header('Service-Worker-Allowed: /');
		echo "importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');\n";
		exit;
	}
}, 1);

/** Push a message to the customer who placed this order (no-op if push isn't set up or the order has no account). */
function nvc_push_order($order, $title, $message, $url = '') {
	$key = nvc_get('nv_onesignal_rest_key');
	$uid = $order->get_customer_id();
	if (!nvc_push_ready() || !$key || !$uid) return false;
	$res = wp_remote_post('https://api.onesignal.com/notifications?c=push', [
		'timeout' => 10,
		'headers' => ['Authorization' => 'Key ' . $key, 'Content-Type' => 'application/json'],
		'body'    => wp_json_encode([
			'app_id'          => nvc_get('nv_onesignal_app_id'),
			'target_channel'  => 'push',
			'include_aliases' => ['external_id' => ['nv-' . $uid]],
			'headings'        => ['en' => $title],
			'contents'        => ['en' => $message],
			'url'             => $url ?: home_url('/'),
		]),
	]);
	if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 300) {
		error_log('[neemveda] push failed: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res)));
		return false;
	}
	return true;
}
