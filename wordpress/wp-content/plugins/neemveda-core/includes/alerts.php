<?php
/**
 * Admin new-order alerts: while any WordPress admin page is open, it checks for new orders every 15 seconds,
 * plays a chime, shows a browser notification and a "New orders" badge in the top bar. An order stops
 * counting as new once the shop owner opens it. The owner also gets WooCommerce's "New order" email.
 */
defined('ABSPATH') || exit;

// Orders placed by customers start unseen.
add_action('woocommerce_checkout_order_processed', function ($order_id) {
	$order = wc_get_order($order_id);
	if ($order) {
		$order->update_meta_data('_nv_unseen', '1');
		$order->save();
	}
});

function nvc_unseen_orders($limit = 10) {
	return wc_get_orders(['limit' => $limit, 'meta_key' => '_nv_unseen', 'meta_value' => '1', 'orderby' => 'date', 'order' => 'DESC']);
}

// Opening an order marks it seen.
add_action('current_screen', function ($screen) {
	if (!current_user_can('edit_shop_orders')) return;
	$id = 0;
	if (isset($_GET['page'], $_GET['action'], $_GET['id']) && $_GET['page'] === 'wc-orders' && $_GET['action'] === 'edit') $id = absint($_GET['id']);
	elseif ($screen && $screen->id === 'shop_order' && isset($_GET['post'])) $id = absint($_GET['post']);
	if ($id && ($order = wc_get_order($id)) && $order->get_meta('_nv_unseen')) {
		$order->delete_meta_data('_nv_unseen');
		$order->save();
	}
});

add_action('wp_ajax_nvc_poll_orders', function () {
	check_ajax_referer('nvc_poll', 'nonce');
	if (!current_user_can('edit_shop_orders')) wp_send_json_error();
	$orders = nvc_unseen_orders(20);
	wp_send_json_success([
		'count'  => count($orders),
		'orders' => array_map(fn($o) => [
			'id'    => $o->get_id(),
			'num'   => $o->get_order_number(),
			'name'  => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
			'total' => html_entity_decode(wp_strip_all_tags(wc_price($o->get_total(), ['decimals' => 0]))),
			'url'   => $o->get_edit_order_url(),
		], $orders),
	]);
});

add_action('admin_bar_menu', function ($bar) {
	if (!current_user_can('edit_shop_orders')) return;
	$n = count(nvc_unseen_orders(20));
	$url = admin_url(class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order');
	$bar->add_node([
		'id'    => 'nv-new-orders',
		'title' => '<span class="ab-icon dashicons dashicons-cart" style="top:2px"></span><span class="nv-ab-count">' . sprintf(esc_html__('New orders: %d', 'neemveda'), $n) . '</span>',
		'href'  => $url,
		'meta'  => ['class' => $n ? 'nv-has-new' : ''],
	]);
}, 80);

add_action('admin_footer', function () {
	if (!current_user_can('edit_shop_orders')) return;
	?>
	<style>#wpadminbar .nv-has-new>.ab-item{background:#E0A93B!important;color:#231F20!important}#wpadminbar .nv-has-new .ab-icon:before{color:#231F20!important}
	#nv-order-toast{position:fixed;right:20px;bottom:20px;z-index:99999;background:#1d2327;color:#fff;padding:14px 16px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.3);display:none;max-width:320px}#nv-order-toast a{color:#ffd77a;font-weight:700}</style>
	<div id="nv-order-toast" role="alert"></div>
	<script>
	(function(){
		var ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode(wp_create_nonce('nvc_poll')); ?>, known=null, ctx=null;
		function chime(){try{ctx=ctx||new (window.AudioContext||window.webkitAudioContext)();[0,.18,.36].forEach(function(t,i){var o=ctx.createOscillator(),g=ctx.createGain();o.frequency.value=[660,880,1100][i];o.connect(g);g.connect(ctx.destination);g.gain.setValueAtTime(.0001,ctx.currentTime+t);g.gain.exponentialRampToValueAtTime(.25,ctx.currentTime+t+.02);g.gain.exponentialRampToValueAtTime(.0001,ctx.currentTime+t+.3);o.start(ctx.currentTime+t);o.stop(ctx.currentTime+t+.32);});}catch(e){}}
		function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
		document.addEventListener('click',function once(){chime.ctxReady=true;try{ctx=ctx||new (window.AudioContext||window.webkitAudioContext)();}catch(e){} if(window.Notification&&Notification.permission==='default')Notification.requestPermission();document.removeEventListener('click',once);});
		function poll(){
			var b=new URLSearchParams({action:'nvc_poll_orders',nonce:nonce});
			fetch(ajax,{method:'POST',credentials:'same-origin',body:b}).then(function(r){return r.json();}).then(function(res){
				if(!res||!res.success)return; var d=res.data, ids=d.orders.map(function(o){return o.id;});
				var node=document.querySelector('#wp-admin-bar-nv-new-orders'); if(node){node.classList.toggle('nv-has-new',d.count>0);var c=node.querySelector('.nv-ab-count');if(c)c.textContent='New orders: '+d.count;}
				if(known){var fresh=d.orders.filter(function(o){return known.indexOf(o.id)<0;});
					if(fresh.length){chime();var o=fresh[0],t=document.getElementById('nv-order-toast');t.innerHTML='🛒 New order <a href="'+esc(o.url)+'">#'+esc(o.num)+'</a><br>'+esc(o.name)+' · '+esc(o.total);t.style.display='block';setTimeout(function(){t.style.display='none';},15000);
						if(window.Notification&&Notification.permission==='granted'){fresh.forEach(function(o){var n=new Notification('New neemveda order #'+o.num,{body:o.name+' · '+o.total,tag:'nv-'+o.id});n.onclick=function(){window.focus();location.href=o.url;};});}}}
				known=ids;
			}).catch(function(){});
		}
		poll(); setInterval(poll,15000);
	})();
	</script>
	<?php
});

// New-order emails also go to the address in neemveda settings.
add_filter('woocommerce_email_recipient_new_order', function ($recipient) {
	$extra = nvc_get('nv_alert_email');
	return $extra && strpos((string) $recipient, $extra) === false ? trim($recipient . ',' . $extra, ',') : $recipient;
});
